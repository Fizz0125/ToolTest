<?php
/**
 * 短信类(发送短信、验证短信)
 * @author: Fizz
 * @time: 2017/9.19
 */

namespace Someline\Tool;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use URL,DB,Cache;

class Sms
{
    const MOBILE_SMS_SESSION_CODE = '_sms_code';//注册与登录下标


    /**
     * 获取手机验证数据
     * @param $data array
     * @return array
     * @author: pyh
     * @time: 2017/7/13
     */
    public function getMobileSmsData($data = array())
    {
        $session_key = $data['session_key'];
        $app         = $data['app'];
        $sms_data = array();
        if (!empty($session_key)) {//小程序
            $sms_data = cache($session_key.static::MOBILE_SMS_SESSION_CODE);//
        } elseif ($app == 1) {//app
            $sms_data = cache('app_'.static::MOBILE_SMS_SESSION_CODE);//
        } else {//wap
            $sms_data = sessions(static::MOBILE_SMS_SESSION_CODE);
        }

        if (!empty($sms_data)) $sms_data = Crypt::decrypt($sms_data);

        return $sms_data;
    }
    /**
     * 获取手机验证数据
     * @param Request $request
     * @param array $sms_data
     * @author: pyh
     * @time: 2017/7/13
     */
    public function saveMobileSmsData($data = array(), $sms_data)
    {
        $session_key = $data['session_key'];
        $app         = $data['app'];
        if (empty($session_key)) {
            cache([$session_key.static::MOBILE_SMS_SESSION_CODE =>Crypt::encrypt($sms_data)],10);//从获取的时候开始算，存十分钟
        } elseif ($app == 1) {

        } else {
            sessions(static::MOBILE_SMS_SESSION_CODE, Crypt::encrypt($sms_data));
        }
    }
    /**
     * 检测手机验证码
     * @param string  $mobile
     * @param string $sms_code
     * @param int $from
	 * @param Request $request
     * @param string &$error_message 1：session无数据 1：验证超过数量 3:验证错误
     * @return bool
     * @author: pyh
     * @time: 2017/7/13
     */
    public function checkMobileSms($mobile, $sms_code, $from = 1,$request)
    {
        $session_key = $request->key;
        $from = $from != 0?$from:($request->from?:1);
        //返回错误
        $return['statusCode'] = 400;
        $return_message = [
            0 => '请获取短信验证码',
            1 => '请重新获取短信验证码',
            2 => '验证码错误',
            3 => '验证码已过期,请重新获取',
        ];
        $data = array(
            'session_key' => $request->key,
            'app'         => $request->app,
        );
        $sms_data = $this->getMobileSmsData($data);
        if (!$sms_data) {
            $return['message'] = $return_message[0];
            return $return;
        }
        --$sms_data['validate_count'];
        //次数小于零后，超过次数报错
        if ($sms_data['validate_count'] < 0) {
            $return['message'] = $return_message[1];
            return $return;
        } elseif (time() > $sms_data['send_time']+600) {
            $return['message'] = $return_message[3];
            return $return;
        } else {
            $id = sms_code_backfill($sms_data['content'], $sms_code, $mobile, $from, 1);//回填验证码,默认成功
            if (($sms_data['mobile'] != $mobile) || ($sms_data['content'] != $sms_code)) {
                if ($id > 0) {//回填验证码，失败
                    sms_code_backfill($sms_data['content'], $sms_code, $mobile, $from, 2, $id);
                }
                $this->saveMobileSmsData($session_key, $sms_data);
                $return['message'] = $return_message[2];
                return $return;
            }
        }

        if (empty($session_key)) {
            unset($_SESSION[static::MOBILE_SMS_SESSION_CODE]);
        } else {
            Cache::forget($session_key.static::MOBILE_SMS_SESSION_CODE);
        }

        $return['statusCode'] = 200;
        return $return;
    }

    /**
     * send 发送短信
     * @param $send_sms_request
     * @return array
     * @author Fizz
     * @time 2018.05.04
     */
    public function send($send_sms_request)
    {
        $data = array();
        $data['statusCode'] = 400;
        $mobile  = $send_sms_request->mobile; //手机号码
        $captcha = $send_sms_request->input('verifyCode'); //图片验证码
        $from    = $send_sms_request->from; //注册来源
        $phm     = $send_sms_request->header('phm')?$send_sms_request->header('phm'):'';//手机型号
        $key     = $send_sms_request->key;
        $app     = $send_sms_request->app;
        $comeFrom= $from;//6代表小程序
        $mobileDayCount = $mobile.'_mobileDayCount';

        $env = config('localsystems.env');//环境
        $time = time();//当前时间
        $endTime = strtotime(date('Y-m-d 23:59:59',$time));//当天0点
        $mobileTime = ($endTime-$time)/60;//次数的存储时间
        $Graphic_Switch = config('localsystems.switch.Graphic_Verification_Code_Switch');//是否打开图片验证码

        $returnMessage = ['短信获取下发失败'];

        //图片验证码弹出规则---START
        $ip = get_client_ip(0,true);
        if ($Graphic_Switch) {
            $PictureCodeRes = $this->validateCode(['mobile' => $mobile, 'captcha' => $captcha, 'app' => $app, 'ip' => $ip]);
            if (!empty($PictureCodeRes)) return array_merge($data, $PictureCodeRes);
        }
        //图片验证码弹出规则---END

        //发送短信次数验证START
        $codeRes = $this->validateCount(['mobile' => $mobile, 'mobileDayCount' => $mobileDayCount, 'mobileTime' => $mobileTime]);
        if (!empty($codeRes)) return array_merge($data, $codeRes);
        //发送短信次数验证END

        //华信科技===START==2017.11.20_Fizz
        if ($env == 'pro') {
            $rand_num = rand(1111, 9999);
            $ipyy_res_sms = $this->ipyy_sms($mobile, $rand_num, $phm, $comeFrom, $ip);

            if (isset($ipyy_res_sms['error'])) {
                $data['message'] = $returnMessage[0];
                return $data;
            }
            if ($ipyy_res_sms['returnstatus'] != 'Success') {//成功
                $data['message'] = $returnMessage[0];
                return $data;
            }
        } else {//测试环境
            $rand_num = '1111';
        }

        $secret = array(
            'mobile'    => $mobile,
            'content'   => $rand_num,
            'send_time' => $time,
            'validate_count' => 3,//可校验次数
        );
        $codes = Crypt::encrypt($secret);

        if (!empty($key)) {//小程序
            cache([$key.static::MOBILE_SMS_SESSION_CODE =>$codes],10);//从获取的时候开始算，存十分钟
        } elseif ($send_sms_request->app == 1) {//app
            cache(['app_'.static::MOBILE_SMS_SESSION_CODE =>$codes],10);//从获取的时候开始算，存十分钟
        } else {//wap
            sessions(static::MOBILE_SMS_SESSION_CODE, $codes);//默认
        }

        //下发成功
        if (Cache::has($mobile)) {//推广页面记录一个手机号首次获取短信验证码
            $count = cache($mobile);
            cache([$mobile=>$count+1],1440);//从获取的时候开始算，存一天
        } else {
            cache([$mobile=>1],1440);//从获取的时候开始算，存一天
        }
        if (Cache::has($ip)) {//2同一IP在5分钟内第二次获取需要输入图形验证码
            $counts = cache($ip);
            cache([$ip=>$counts+1],5);//存五分钟
        } else {
            cache([$ip=>1],5);//存五分钟
        }

        $data['statusCode'] = 200;

        //发送成功记录日志
        $sms_log = array();
        $sms_log['user_id']     = 0;
        $sms_log['phonenum']    = $mobile;
        $sms_log['create_time'] = $time;
        $sms_log['content']     = $rand_num;
        $sms_log['send_ip']     = $ip;
        $sms_log['from_type']   = $_SERVER['REQUEST_URI'];
        $sms_log['json_data']   = $env == 'pro'?json_encode($ipyy_res_sms):'测试';
        DB::table('sms_log')->insertGetId($sms_log);
        //发送成功记录日志 End

        //记录发送信息
        $this->saveValidateCount($mobileDayCount, $time, $mobileTime);

        return $data;
    }

    /**
     * ipyy_sms 华信科技
     * @param string $mobile 要发送的手机号码
     * @param string $rand_num 验证码
     * @return bool
     * @author Fizz
     * @time 2017.12.8
     */
    private function ipyy_sms($mobile = '', $rand_num = '', $phm = '', $comeFrom, $ip)
    {
        $res_arr = array();
        if (empty($mobile) || empty($rand_num)) {
            $res_arr['error'] == false;
            return $res_arr;
        }

        $send_url = 'https://dx.ipyy.net/smsJson.aspx?';//(返回值为json格式)
        $account  = config('localsystems.ipyy.account');//发送用户帐号
        $password = config('localsystems.ipyy.password');//发送用户帐号
        //$content  = '【自客】您的验证码为：'.$rand_num.'，10分钟内有效，感谢您使用自客。';//自客，发送内容
        $content  = '【自客】感谢使用小灯塔，您的验证码为：'.$rand_num.'，10分钟内有效。';//灯塔，发送内容

        $send_data = array(
            'account'  => $account,
            'password' => $password,
            'action'   => config('localsystems.ipyy.action'),//发送任务命令
            'mobile'   => $mobile,//发送的手机号码
            'content'  => $content
        );

        $url = $send_url.http_build_query($send_data);

        //记录日志===START
        ///短信日志表
        $send_log = array();
        $send_log['post_url']     = $url;
        $send_log['create_time']  = time();
        $send_log['TemplateCode'] = '';//暂无
        $send_log['ParamString']  = '{"code":"'.$rand_num.'"}';
        $send_log['phonenum']     = $mobile;
        $send_log['type']         = "ipyy";
        $send_log['ip']           = $ip;
        $send_log['from_act']     = $_SERVER['REQUEST_URI'];//MODULE_NAME.'_'.CONTROLLER_NAME .'_'. ACTION_NAME;
        $send_log['phone_model']  = $phm;
        $send_log['data_from']    = $comeFrom;
        $send_log_id = DB::table('sms_send_log')->insertGetId($send_log);
        //记录日志===END

        $res = $this->https_request($url);

        //修改记录日志的状态START
        if ($res != false) {
            $res_arr = json_decode($res,true);
            if (isset($res_arr['returnstatus']) && $res_arr['returnstatus'] == 'Success') {//成功
                $send_log = array();
                $send_log['status']          = 1;
                $send_log['api_return_data'] = $res;
                DB::table('sms_send_log')->where(array('id'=>$send_log_id))->update($send_log);
            } else {
                $send_log = array();
                $send_log['status']          = 2;
                $send_log['api_return_data'] = $res;
                DB::table('sms_send_log')->where(array('id'=>$send_log_id))->update($send_log);
                //记录错误日志(记录为严重错误)_Fizz_2017.9.7
                error_log(date('Y-m-d H:i:s').":--- ".$mobile."在".$comeFrom."获取短信失败---://来源，0:APP，1:Web，2:Wap,3:安卓,4:ios,6灯塔\n",3,__DIR__.'/../../storage/logs/sms.log');

                $res_arr['error'] == false;
            }
        } else {//失败
            $send_log = array();
            $send_log['status']          = 2;
            $send_log['api_return_data'] = $res;
            DB::table('sms_send_log')->where(array('id'=>$send_log_id))->update($send_log);
            //记录错误日志(记录为严重错误)_Fizz_2017.9.7
            error_log(date('Y-m-d H:i:s').":--- ".$mobile."在".$comeFrom."获取短信失败---://来源，0:APP，1:Web，2:Wap,3:安卓,4:ios,6灯塔\n",3,__DIR__.'/../../storage/logs/sms.log');

            $res_arr['error'] == false;
        }
        //修改记录日志的状态END

        return $res_arr;
    }

    /**
     * https_request curl请求
     * @param $url
     * @return bool|mixed
     * @author Fizz
     * @time 2017.12.8
     */
    private function https_request($url)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $data = curl_exec($curl);
        if (curl_errno($curl)) {
            //记录日志:---Fizz---2017.11.10---:
            error_log(date('Y-m-d H:i:s').":---START 华信文件https_request方法---:\n".curl_error($curl)."\n:---END helpers文件https_request方法---:\n",3,__DIR__.'/../../storage/logs/curl.log');
            //return 'ERROR '.curl_error($curl);
            return false;
        }
        curl_close($curl);
        return $data;
    }

    /**
     * validateCode 验证图片验证码
     * @param array $data
     * @return array
     * @author Fizz
     * @time 2018.05.03
     */
    private function validateCode ($datas = array())
    {
        $mobile = $datas['mobile'];//手机号
        $captcha= $datas['captcha'];//图片验证码
        $app    = $datas['app'];//app，0否1是
        $ip     = $datas['ip'];//ip
        $time = time();//当前时间
        $Graphic_LIMIT = config('localsystems.switch.Graphic_Verification_Code_LIMIT');//当天获取多少次短信后就弹出验证码

        $returnMessage = [
                            0 => '请获取图片验证码',
                            1 => '请重新获取图片验证码'
                        ];

        if ((Cache::has($mobile) && cache($mobile) + 1 > $Graphic_LIMIT) || (Cache::has($ip) && cache($ip) + 1 > $Graphic_LIMIT)) {//
            //判断是否需要填写图片验证码
            $is_openid = $GLOBALS['openid']??false;//小程序

            if ($is_openid) {//小程序
                $pictureCode = cache($GLOBALS['openid'].'_captcha');//是否已经获取图片验证码

                if(!$captcha || !$pictureCode) {
                    $data['data']['imgcodeUrl']   = env('BASE_HOST_URL').'/wx/appletCaptchas'.'?'.http_build_query(array('t'=>$GLOBALS['openid'], 'times' => $time));
                    $data['message'] = $returnMessage[0];
                    return $data;
                }
                if ($captcha && strtoupper($pictureCode) == strtoupper($captcha)) {
                    Cache::forget($GLOBALS['openid'].'_captcha');
                }else{
                    $data['data']['imgcodeUrl']   = env('BASE_HOST_URL').'/wx/appletCaptchas'.'?'.http_build_query(array('t'=>$GLOBALS['openid'], 'times' => $time));
                    $data['message'] = $returnMessage[1];
                    return $data;
                }
            } elseif ($app == 1) {//app
                $pictureCode = cache($mobile.'_captcha');//是否已经获取图片验证码

                if(!$captcha || !$pictureCode) {
                    $data['imgcodeUrl']   = env('APP_URL').'/mobile/currency/getPictureCode'.'?'.http_build_query(array('t'=>$mobile, 'times' => $time));
                    $data['message'] = $returnMessage[0];
                    return $data;
                }
                if ($captcha && strtoupper($pictureCode) == strtoupper($captcha)) {
                    Cache::forget($mobile.'_captcha');
                }else{
                    $data['imgcodeUrl']   = env('APP_URL').'/mobile/currency/getPictureCode'.'?'.http_build_query(array('t'=>$mobile, 'times' => $time));
                    $data['message'] = $returnMessage[1];
                    return $data;
                }
            } else {//session
                $pictureCode_session = $_SESSION['code']??0;//是否已经获取图片验证码

                if(!$captcha || !$pictureCode_session) {
                    $data['data']['imgcodeUrl']   = env('BASE_HOST_URL').'/beaconserver/wap/currency/captchas'.'?'.http_build_query(array('t'=>$time));
                    $data['message'] = $returnMessage[0];
                    return $data;
                }
                if ($captcha && strtoupper($pictureCode_session) == strtoupper($captcha)) {
                    unset($_SESSION['code']);
                }else{
                    $data['data']['imgcodeUrl']   = env('BASE_HOST_URL').'/beaconserver/wap/currency/captchas'.'?'.http_build_query(array('t'=>$time));
                    $data['message'] = $returnMessage[1];
                    return $data;
                }
            }
        }
        return '';
    }

    /**
     * validateCount 验证发送次数
     * @param array $data
     * @return array|string
     * @author Fizz
     * @time 2018.05.04
     */
    private function validateCount($datas = array())
    {
        $mobile  = $datas['mobile'];//手机号
        $mobileDayCount  = $datas['mobileDayCount'];//存放缓存KEY
        $time    = time();
        $mobileTime = $datas['mobileTime'];//保存时间
        $sms_Switch = config('localsystems.switch.SMS_MINUTE_LIMIT');//是否限制获取短信的频率

        $returnMessage = [
                            0 => '一分钟内不可重复发送',
                            1 => '短信获取过于频繁，请稍后再试！',
                            2 => '当天获取短信次数已达上限，请明天再试！'
                        ];

        $codes = array(
                    'mobile' 		 => $mobile,
                    'mobileDayCount' => 0,
                    'sendTime' 	     => $time,//最后一条的发送时间
                    'sendTimeList' 	 => array()//发送短信的记录
                );

        if (Cache::has($mobileDayCount)) {//一天发送短信次数
            $sendCountdecrypt = Crypt::decrypt(cache($mobileDayCount));
            $smsCount = $sendCountdecrypt['mobileDayCount'];
            $sendTime  = $sendCountdecrypt['sendTime'];

            if (env('APP_ENV') == 'pro' || $sms_Switch) {
                //一分钟内只能发一次
                if ($time < $sendTime+60) {
                    $data['message'] = $returnMessage[0];
                    return $data;
                }
                //一小时内只能发5条
                if (!empty($sendCountdecrypt['sendTimeList'])) {
                    $listcount= count($sendCountdecrypt['sendTimeList']);
                    //一天内最多十条
                    if ($smsCount > 9) {
                        $data['message'] = $returnMessage[2];
                        return $data;
                    }

                    if (($smsCount > 4) && ($smsCount[0] - $smsCount[4]) < 3600) {
                        $data['message'] = $returnMessage[1];
                        return $data;
                    }
                }
            }
        } else {
            $sendCountencrypt = Crypt::encrypt($codes);
            cache([$mobileDayCount=>$sendCountencrypt],$mobileTime);//从获取的时候开始算，存一天
        }

        return '';
    }

    /**
     * saveValidateCount 保存发送短信信息
     * @param $mobileDayCount
     * @param $time
     * @param $mobileTime
     * @return void
     * @author Fizz
     * @time 2018.05.03
     */
    private function saveValidateCount($mobileDayCount, $time, $mobileTime)
    {
        //记录发送时间信息等
        $mobileDayCountInfo = cache($mobileDayCount);
        $sendCountdecrypt = Crypt::decrypt($mobileDayCountInfo);
        array_unshift($sendCountdecrypt['sendTimeList'], $time);
        $sendCountdecrypt['mobileDayCount'] += 1;
        $mobileDayCountInfo = Crypt::encrypt($sendCountdecrypt);
        cache(['$mobileDayCount' => $mobileDayCountInfo], $mobileTime);
    }
}