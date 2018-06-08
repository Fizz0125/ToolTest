<?php
/**
 * OSS APP 上传类
 * User: hello
 * Date: 2018/5/11
 * Time: 16:11
 */

namespace Someline\Tool\OssSts;

use Illuminate\Support\Facades\Log;
use Someline\Exceptions\MobileApiException;
use Sts\Request\V20150401 as Sts;

include_once 'aliyun-php-sdk-core/Config.php';

class OssApp
{
    protected static $_instance = null;

    private $accessKeyID;
    private $accessKeySecret;
    private $roleArn;
    private $tokenExpire;
    private $policy;

    public static function instance()
    {
        if (!self::$_instance) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    protected function __construct()
    {
        $this->accessKeyID = env('OSS_ACCESS_KEY_ID');
        $this->accessKeySecret = env('OSS_ACCESS_KEY_SECRET');
        $this->tokenExpire = env('OSSAPP_TOKEN_EXPIRE');
        $this->roleArn = env('OSSAPP_ROLE_ARN');
        $this->policy = $this->read_file(app_path('/Tool/OssSts/policy/all_policy.txt'));
    }

    public function getUploadConfig()
    {
        $iClientProfile = \DefaultProfile::getProfile("cn-shenzhen", $this->accessKeyID, $this->accessKeySecret);
        $client = new \DefaultAcsClient($iClientProfile);

        $request = new Sts\AssumeRoleRequest();
        $request->setRoleSessionName("client_name");
        $request->setRoleArn($this->roleArn);
        //$request->setPolicy($this->policy);
        $request->setDurationSeconds($this->tokenExpire);
        $response = $client->doAction($request);

        $rows = array();
        $body = $response->getBody();
        $content = json_decode($body);
        if ($response->getStatus() == 200)
        {
            $rows['StatusCode'] = 200;
            $rows['AccessKeyId'] = $content->Credentials->AccessKeyId;
            $rows['AccessKeySecret'] = $content->Credentials->AccessKeySecret;
            $rows['Expiration'] = $content->Credentials->Expiration;
            $rows['SecurityToken'] = $content->Credentials->SecurityToken;
        }
        else
        {
            $rows['StatusCode'] = 500;
            $rows['ErrorCode'] = $content->Code;
            $rows['ErrorMessage'] = $content->Message;
            Log::error('OSS授权失败:'.$body);
        }
        return $rows;
    }

    function read_file($fname)
    {
        $content = '';
        if (!file_exists($fname)) {
            throw new MobileApiException('OSS配置文件错误');
        }
        $handle = fopen($fname, "rb");
        while (!feof($handle)) {
            $content .= fread($handle, 10000);
        }
        fclose($handle);
        return $content;
    }
}