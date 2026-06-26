<?php

namespace ByJG\Gluo\Controller;

use ByJG\Gluo\Attribute\ValidateRequest;
use ByJG\Gluo\Model\BaseUser;
use ByJG\Gluo\Repository\BaseRepository;
use ByJG\Gluo\Util\JwtContext;
use ByJG\Gluo\Util\OpenApiContext;
use ByJG\Authenticate\Service\UsersService;
use ByJG\Config\Config;
use ByJG\Mail\Wrapper\MailWrapperInterface;
use ByJG\RestServer\Enum\OutputMode;
use ByJG\RestServer\Exception\Error400Exception;
use ByJG\RestServer\Exception\Error401Exception;
use ByJG\RestServer\Exception\Error422Exception;
use ByJG\RestServer\HttpRequest;
use ByJG\RestServer\HttpResponse;
use ByJG\XmlUtil\XmlDocument;
use RuntimeException;

abstract class BaseLoginController
{
    /**
     * Override to include extra fields in the JWT token beyond the defaults.
     */
    protected function extraTokenFields(): array
    {
        return [];
    }

    /**
     * Override to change the email template used for password reset codes.
     */
    protected function getResetEmailTemplate(): string
    {
        return 'email_code.html';
    }

    /**
     * Override to change token expiry (in seconds). Defaults to 1 hour.
     */
    protected function tokenExpiry(): int
    {
        return 3600;
    }

    public function post(HttpResponse $response, HttpRequest $request): void
    {
        $json = ValidateRequest::getPayload() ?? [];

        $userToken = JwtContext::createUserMetadata($json["username"], $json["password"]);

        if ($userToken === null) {
            throw new Error401Exception("Failed to create user token");
        }

        $response->getResponseBody()->serializeAs(OutputMode::SingleObject);
        $response->write(['token' => $userToken->token]);
        $response->write(['data' => $userToken->data]);
    }

    public function refreshToken(HttpResponse $response, HttpRequest $request): void
    {
        $diff = (intval($request->attributeString("jwt.exp")) - time()) / 60;

        if ($diff > 5) {
            throw new Error401Exception("You only can refresh the token 5 minutes before expire");
        }

        /** @var UsersService $usersService */
        $usersService = Config::get(UsersService::class);
        $userId = JwtContext::getUserId();

        if ($userId === null) {
            throw new Error401Exception("User ID not found in token");
        }

        $userModel = $usersService->getById($userId);

        if ($userModel === null) {
            throw new Error401Exception("User not found");
        }

        /** @var BaseUser $user */
        $user = $userModel;
        $metadata = JwtContext::createUserMetadata($user);

        if ($metadata === null) {
            throw new Error401Exception("Failed to create user metadata");
        }

        $response->getResponseBody()->serializeAs(OutputMode::SingleObject);
        $response->write(['token' => $metadata->token]);
        $response->write(['data' => $metadata->data]);
    }

    public function postResetRequest(HttpResponse $response, HttpRequest $request): void
    {
        $json = OpenApiContext::validateRequest($request);

        if ($json instanceof XmlDocument) {
            throw new Error400Exception("Cannot accept content type xml");
        }

        $usersService = Config::get(UsersService::class);
        $user = $usersService->getByEmail($json["email"]);

        $token = BaseRepository::getUuid();
        $code = strval(rand(10000, 99999));

        if (!is_null($user)) {
            $expireTimestamp = strtotime('+10 minutes');
            if ($expireTimestamp === false) {
                throw new RuntimeException("Failed to calculate expiration time");
            }
            $user->set(BaseUser::PROP_RESETTOKEN, $token);
            $user->set(BaseUser::PROP_RESETTOKENEXPIRE, date('Y-m-d H:i:s', $expireTimestamp));
            $user->set(BaseUser::PROP_RESETCODE, $code);
            $user->set(BaseUser::PROP_RESETALLOWED, null);
            $usersService->save($user);

            $mailWrapper = Config::get(MailWrapperInterface::class);
            $envelope = Config::get('MAIL_ENVELOPE', [$json["email"], "Service - Password Reset", $this->getResetEmailTemplate(), [
                "code" => trim(chunk_split($code, 1, ' ')),
                "expire" => 10
            ]]);

            $mailWrapper->send($envelope);
        }

        $response->write(['token' => $token]);
    }

    protected function validateResetToken(HttpResponse $response, HttpRequest $request): array
    {
        $json = OpenApiContext::validateRequest($request);

        if ($json instanceof XmlDocument) {
            throw new Error400Exception("Cannot accept content type xml");
        }

        $usersService = Config::get(UsersService::class);
        $user = $usersService->getByEmail($json["email"]);

        if (is_null($user)) {
            throw new Error422Exception("Invalid data");
        }

        if ($user->get("resettoken") !== ($json["token"] ?? null)) {
            throw new Error422Exception("Invalid data");
        }

        if (strtotime($user->get("resettokenexpire")) < time()) {
            throw new Error422Exception("Invalid data");
        }

        return [$usersService, $user, $json];
    }

    public function postConfirmCode(HttpResponse $response, HttpRequest $request): void
    {
        [$usersService, $user, $json] = $this->validateResetToken($response, $request);

        if ($user->get("resetcode") != $json["code"]) {
            throw new Error422Exception("Invalid data");
        }

        $user->set("resetallowed", "yes");
        $usersService->save($user);

        $response->write(['token' => $json["token"]]);
    }

    public function postResetPassword(HttpResponse $response, HttpRequest $request): void
    {
        [$usersService, $user, $json] = $this->validateResetToken($response, $request);

        if ($user->get("resetallowed") != "yes") {
            throw new Error422Exception("Invalid data");
        }

        $user->setPassword($json["password"]);
        $user->set("resettoken", null);
        $user->set("resettokenexpire", null);
        $user->set("resetcode", null);
        $user->set("resetallowed", null);
        $usersService->save($user);

        $response->write(['token' => $json["token"]]);
    }
}
