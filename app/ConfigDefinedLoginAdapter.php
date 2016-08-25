<?php

namespace App;

use Aura\Auth\Adapter\AdapterInterface;
use Aura\Auth\Auth;
use Aura\Auth\Status;

class InvalidLoginException extends \Exception {}

class ConfigDefinedLoginAdapter implements AdapterInterface
{
    protected $acl;

    function __construct($acl) {
        $this->acl = $acl;
    }

    // AdapterInterface::login()
    public function login(array $input)
    {
        if ($this->isLegit($input)) {
            $username = $input['user'];
            $userdata = $this->acl[$username]['data'];
            $this->updateLoginTime(time());
            return array($username, $userdata);
        } else {
            throw new InvalidLoginException('Invalid login details. Please try again.');
        }
    }

    // AdapterInterface::logout()
    public function logout(Auth $auth, $status = Status::ANON)
    {
        $this->updateLogoutTime($auth->getUsername(), time());
    }

    // AdapterInterface::resume()
    public function resume(Auth $auth)
    {
        $this->updateActiveTime($auth->getUsername(), time());
    }

    // custom support methods not in the interface

    public function setACL(array $list) {
        $acl = $list;
    }

    protected function isLegit($input) {
        $user = $input['user'];
        $pass = $input['password'];
        if (array_key_exists($user, $this->acl)) {
            if ($this->acl[$user]['password'] == $pass) {
                return true;
            }
        }
        return false;
    }

    protected function updateLoginTime($time) { }

    protected function updateActiveTime($time) { }

    protected function updateLogoutTime($time) { }
}