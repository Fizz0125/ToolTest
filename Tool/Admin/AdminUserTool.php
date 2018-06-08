<?php
/**
 * 求职者用户工具类
 * @author: pyh
 * @time: 2017/8/21
 */

namespace Someline\Tool\Admin;



class AdminUserTool
{
	protected static $uid_session_key = 'eliteUser';
	protected static $_instance = null;
	/**
	 * 用户数据
	 * @author: pyh
	 * @time: 2017/8/9
	 */
	protected $user_data = [];
	protected function __construct()
	{

	}
	protected function __clone()
	{
		// TODO: Implement __clone() method.
	}

	public static function instance()
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
	 * @author: pyh
	 * @time: 2017/8/9
	 */
	protected function init()
	{
		$this->user_data['uid'] = sessions('mid');
		$this->user_data['user_auth'] = sessions('user_auth');
		$this->user_data['user_auth_sign'] = sessions('user_auth_sign');
	}


	public static function hasLogin()
	{
		$uid = sessions('mid');
		return $uid??false;
	}

}