<?php

namespace Tests\Model;

use ByJG\AnyDataset\Db\DatabaseExecutor;
use ByJG\AnyDataset\Db\Factory;
use ByJG\Authenticate\Repository\UsersRepository;
use ByJG\MicroOrm\ORM;
use Override;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\User;

class BaseUserTest extends TestCase
{
    private string $dbFile;

    #[Override]
    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/gluo-base-user-' . uniqid() . '.db';
    }

    #[Override]
    protected function tearDown(): void
    {
        // The repository registers the User mapper in the ORM's memory: no other test may see it
        ORM::resetMemory();
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function testHashesPasswordsWithBcrypt(): void
    {
        $repository = new UsersRepository(DatabaseExecutor::using(Factory::getDbInstance('sqlite://' . $this->dbFile)), User::class);

        $password = $repository->getMapper()->getFieldMap('password')?->getUpdateFunctionValue('!P4ssw0rdstr!', null);

        $this->assertSame(PASSWORD_BCRYPT, password_get_info((string)$password)['algo']);
        $this->assertSame(sha1('old'), $repository->getMapper()->getFieldMap('password')?->getUpdateFunctionValue(sha1('old'), null), 'a SHA-1 hash is kept until the next login');
    }
}
