<?php

declare(strict_types=1);

use AuthKit\Auth;
use AuthKit\Login;
use AuthKit\User;
use AuthKit\Exception\AuthException;
use AuthKit\Storage\PdoUserStorage;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    /**
     * @var PDO
     */
    private PDO $pdo;

    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @var Auth Auth instance with throwExceptions enabled.
     */
    private Auth $authStrict;

    /**
     * Initialises an in-memory SQLite database and two Auth instances:
     * - $auth        — default (returns Login on failure)
     * - $authStrict  — throwExceptions: true (throws AuthException on failure)
     */
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $storage = new PdoUserStorage($this->pdo);
        $storage->createSchema();

        $this->auth       = new Auth($storage, null, 600);
        $this->authStrict = new Auth($storage, null, 600, null, true);
    }

    /**
     * Successful registration followed by a successful login.
     */
    public function testRegisterAndLogin(): void
    {
        $user = $this->auth->register('test@example.com', 'Password123!');
        $this->assertInstanceOf(User::class, $user);

        $login = $this->auth->login('test@example.com', 'Password123!');
        $this->assertInstanceOf(Login::class, $login);
        $this->assertTrue($login->isSuccess());
        $this->assertIsString($login->token());
        $this->assertNotEmpty($login->token());
        $this->assertTrue($this->auth->isLoggedIn());

        $loggedInUser = $this->auth->getUser();
        $this->assertInstanceOf(User::class, $loggedInUser);
        $this->assertEquals('test@example.com', $loggedInUser->get('email'));
    }

    /**
     * Login with a wrong password returns Login::failure() by default.
     */
    public function testLoginFailureWrongPassword(): void
    {
        $this->auth->register('fail@example.com', 'CorrectPass1!');

        $login = $this->auth->login('fail@example.com', 'WrongPassword!');
        $this->assertInstanceOf(Login::class, $login);
        $this->assertTrue($login->isFailure());
        $this->assertNotEmpty($login->message());
    }

    /**
     * Login with a wrong password throws AuthException when throwExceptions is enabled.
     */
    public function testLoginFailureThrowsWhenStrictMode(): void
    {
        $this->authStrict->register('strict@example.com', 'CorrectPass1!');

        $this->expectException(AuthException::class);
        $this->authStrict->login('strict@example.com', 'WrongPassword!');
    }

    /**
     * Successful logout clears the session.
     */
    public function testLogout(): void
    {
        $this->auth->register('logout@example.com', 'SecurePass123!');
        $this->auth->login('logout@example.com', 'SecurePass123!');
        $this->assertTrue($this->auth->isLoggedIn());

        $this->auth->logout();
        $this->assertFalse($this->auth->isLoggedIn());
    }
}
