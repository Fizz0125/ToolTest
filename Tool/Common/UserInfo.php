<?php
/**
 * 用户类
 * @author: Fizz
 * @time: 2017.9.28
 */

namespace Someline\Tool\Common;

use Illuminate\Support\Facades\Session;

class UserInfo
{
    private static $applet_unionid = 'applet_unionid';
    private static $applet_openid  = 'applet_openid';
    private static $_instance;

	/**
	 * 用户数据
	 * @author: Fizz
	 * @time: 2017.9.25
	 */
	protected $user_data = [];
    private function __construct()
	{

	}
    private function __clone()
	{
		// TODO: Implement __clone() method.
	}

	public static function getInstance()
	{
		if (!self::$_instance) {
			self::$_instance = new self();
			self::$_instance->init();
		}
		return self::$_instance;
	}

	public function __get($name)
	{
		return $this->user_data[$name]??'';
	}
	/**
	 * 初始化数据
	 * @author: Fizz
	 * @time: 2018.01.04
	 */
	protected function init()
	{
        $this->user_data['openid']  = sessions(self::$applet_unionid)??'';
        $this->user_data['unionid'] = sessions(self::$applet_openid)??'';
	}
}