<?php

use PDO;
use AuthKit\Auth;
use AuthKit\User;
use AuthKit\Storage\PdoUserStorage;
use AuthKit\Exception\AuthException;
use PHPUnit\Framework\TestCase;


class AuthTest extends TestCase
{
    /**
     * @var PDO
     */
    // This property holds the PDO instance used for database operations.
    private PDO $pdo;

    /**
     * @var Auth
     */
    // This property holds the Auth instance used for authentication operations.
    // It is initialized in the setUp() method and used in the test methods to perform
    // user registration, login, and logout operations.
    // The Auth instance interacts with the PdoUserStorage to manage user sessions and authentication.
    // It provides methods for registering users, logging them in, checking if a user is logged
    // in, and logging them out.
    private Auth $auth;

    /**
     * Sets up the test environment.
     * Initializes a PDO connection to an in-memory SQLite database,
     * creates the user storage schema, and initializes the Auth instance.
     */
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $storage = new PdoUserStorage($this->pdo);
        $storage->createSchema();
        $this->auth = new Auth($storage, null, 600);
    }

    /**
     * Cleans up the test environment.
     * Closes the PDO connection.
     */
    public function testRegisterAndLogin(): void
    {
        $user = $this->auth->register("test@example.com", "Password123!", ["active" => 1]);
        $this->assertInstanceOf(User::class, $user);

        $token = $this->auth->login("test@example.com", "Password123!");
        $this->assertIsString($token);
        $this->assertTrue($this->auth->isLoggedIn());

        $loggedInUser = $this->auth->getUser();
        $this->assertInstanceOf(User::class, $loggedInUser);
        $this->assertEquals("test@example.com", $loggedInUser->get("email"));
    }

    // Tests the registration of a user with valid credentials.
    public function testLoginFailureWrongPassword(): void
    {
        $this->auth->register("fail@example.com", "CorrectPass1!");

        $this->expectException(AuthException::class);
        $this->auth->login("fail@example.com", "WrongPassword!");
    }

    // Tests the login failure when the user does not exist.
    public function testLogout(): void
    {
        $this->auth->register("logout@example.com", "SecurePass123!");
        $this->auth->login("logout@example.com", "SecurePass123!");
        $this->assertTrue($this->auth->isLoggedIn());

        $this->auth->logout();
        $this->assertFalse($this->auth->isLoggedIn());
    }
}
