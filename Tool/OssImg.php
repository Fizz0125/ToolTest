<?php
/**
 * oss 图片处理服务
 * @author: Arno
 * @time: 2018/01/10
 */

namespace Someline\Tool;

class OssImg extends Oss
{
    protected $config = [
        'type' => '',
        'm' => 0,
        'h' => 0,
        'limit' => 1,
         
    ];
    
    public function __construct($config = array())
    {
        /* 获取配置 */
        $this->config = array_merge($this->config, $config);
    }
    
    public function __get($name)
    {
        return $this->config[$name];
    }
    
    /**
     * oss图片处理,只能jpg、png、bmp、gif、webp、tiff
     * @param string $path 图片地址
     * @param array $operate 参数设置 ['<operation>'] => ['<param>','<...>']
     * @return string 重新组合的地址
     * @author Arno
     * @time 2017/12/26
     */
    public function processPicture(String $path)
    {
        dd($this->config);
        //操作列表
        $operation_list = array(
            'resize' => 'image/resize',//缩放
            'crop'   => 'image/crop'//裁剪
        );
         
        /* //预设置配置
         $rule_list = array(
         'share_img' => [
         'resize' => array('m_fill','w_690','h_552','limit_0')
         ]
         );
          
         if($type != 1){
         $operate = $rule_list[$type];
         } */
        $operate = [];
        $operate_list = array();//执行操作
        foreach($operate as $key=>$value){
            $value = array_filter($value);
            if(empty($value)){
                continue;
            }
             
            $param = implode(',', $value);
            if(empty($operation_list[$key])){
                continue;
            }else{
                array_push($operate_list,'x-oss-process='.$operation_list[$key].','.$param);
            }
             
        }
        $url = join(',', $operate_list);
        return $path.'?'.$url;
    }
}

