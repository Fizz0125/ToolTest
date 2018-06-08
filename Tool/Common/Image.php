<?php
/**
 * 图片合成类
 */
namespace Someline\Tool\Common;

class Image
{
	//资源
	private $img;
	//画布宽度
	private $width=100;
	//画布高度
	private $height=30;
	//背景颜色
	private $bgColor='#ffffff';
	//验证码字体
	private $font;
	//验证码字体大小
	private $fontSize=22;
	//验证码字体颜色
	private $fontColor='';

    /**
     * [$instance description]
     * @var null
     */
    protected static $instance = null;

    /**
     * 单例
     * @return [type] [description]
     */
    public static function getInstance()
    {
        if(empty(static::$instance)){
            static::$instance = new static();
        }
        return static::$instance;
    }

	public function __construct() {

	}

	//设置字体文件
	public function font($font)
	{
		$this->font= $font;
		return $this;
	}

	//设置文字大小
	public function fontSize($fontSize)
	{
		$this->fontSize=$fontSize;
		return $this;
	}

	//设置字体颜色
	public function fontColor($fontColor)
	{
		$this->fontColor = $fontColor;
		return $this;
	}

	//验证码数量
	public function num($num)
	{
		$this->codeLen=$num;
		return $this;
	}

	//设置宽度
	public function width($width)
	{
		$this->width = $width;
		return $this;
	}

	//设置高度
	public function height($height)
	{
		$this->height = $height;
		return $this;
	}

	//建画布
	private function create() {
		if (!$this->checkGD())
			return false;
		$w = $this->width;
		$h = $this->height;
		$bgColor = $this->bgColor;
		$img = imagecreatetruecolor($w, $h);
		$bgColor = imagecolorallocate($img, hexdec(substr($bgColor, 1, 2)), hexdec(substr($bgColor, 3, 2)), hexdec(substr($bgColor, 5, 2)));
		imagefill($img, 0, 0, $bgColor);
		$this->img = $img;
		$this->createLine();
		$this->createFont();
		$this->createPix();
		$this->createRec();
	}


	//写入验证码文字
	private function createFont() {
		$this->createCode();
		$color = $this->fontColor;
		if (!empty($color)) {
			$fontColor = imagecolorallocate($this->img, hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2)));
		}
		$x = ($this->width - 10) / $this->codeLen;
		for ($i = 0; $i < $this->codeLen; $i++) {
			if (empty($color)) {
				$fontColor = imagecolorallocate($this->img, mt_rand(50, 155), mt_rand(50, 155), mt_rand(50, 155));
			}
			imagettftext($this->img, $this->fontSize, mt_rand(- 30, 30), $x * $i + mt_rand(6, 10), mt_rand($this->height / 1.3, $this->height - 5), $fontColor, $this->font, $this->code [$i]);
		}
		$this->fontColor = $fontColor;
	}

	//验证GD库
	private function checkGD() {
		return extension_loaded('gd') && function_exists("imagepng");
	}

    /**
     * 创建缩略图
     * @param String $filename 原图地址
     * @param String $path 新图保存路径
     * @param Int $width 新图缩放宽度
     * @param Int $height 新图缩放高度
     * @param bool $forceReset 强制改变 or 定位中心线按比例改变大小（默认后者）
     */
    function ImageResize($filename, $path, $width, $height, $forceReset = false) {
        $is_http = strripos($filename,'http');
        if ($is_http !== false) {
            // 先生成一个临时文件
            $images_content = curl($filename,null,'get');
            $filename =  md5($path);
            file_put_contents($filename,$images_content);
        }
        //获取原图信息
        $img_info = getimagesize($filename);
        $w = $img_info[0];//取得原始图片的宽
        $h = $img_info[1];//取得原始图片的高

        //生成缩略图名称
//        $newImage = 'thumb_'.uniqid();
        //$newImage='thumb_'.mb_substr($filename,mb_strrpos($filename,'/')+1,32);
        //根据原图类型加载原图
        switch($img_info[2]){
            case 1:
                $imgCreate = imagecreatefromgif($filename);
//                $newImage = '.gif';
                break;
            case 2:
                $imgCreate = imagecreatefromjpeg($filename);
//                $newImage = '.jpg';
                break;
            case 3:
                $imgCreate = imagecreatefrompng($filename);
//                $newImage = '.png';
                break;
            default:
                return false;
        }

        //创建缩略图画布
        $thumb = imagecreatetruecolor($width, $height);

        if($forceReset){
            //将原图按比例复制到缩略图上
            imagecopyresampled($thumb, $imgCreate, 0, 0, 0, 0, $width, $height, $w, $h);
        }else{
            $p = $width / $height;
            if($w > $h * $p){
                $s_x = ($w - $h * $p)/2;
                $s_y = 0;
                $w = $h * $p;
            }else if($w < $h * $p){
                $s_x = 0;
                $s_y = ($h - $w / $p)/2;
                $h = $w / $p;
            }else{
                $s_x = 0;
                $s_y = 0;
            }
            //缩放图片到新图上，并将多余部分裁剪掉
            imagecopyresampled($thumb, $imgCreate, 0, 0, $s_x, $s_y, $width, $height, $w, $h);
        }

        switch($img_info[2]){
            case 1:
                imagegif($thumb, $path);
                break;
            case 2:
                imagejpeg($thumb, $path);
                break;
            case 3:
                imagepng($thumb, $path);
                break;
        }
        if ($is_http !== false) {
            unlink($filename);
        }
        return $path;
    }

    /**
     * createWaterPicture 生成水印图
     * @param $filename
     * @param $watername
     * @param string $path
     * @param int $offset
     * @param int $opacity
     * @return bool|string
     * @author Fizz
     * @time 2018.01.30
     */
    function createWaterPicture($filename, $watername, $path = '', $offset = 9,$opacity=100){
        //获取原图信息
        $img_info = getimagesize($filename);
        $w = $img_info[0]; //原图宽度
        $h = $img_info[1]; //原图高度

        //定义新图片名称
        $newImage = 'thumb_' . date('YmdHis') . rand(1000,9999);

        //根据原图类型加载原图
        switch($img_info[2]){
            case 1:
                $imgCreate = imagecreatefromgif($filename);
                $newImage = $newImage . '.gif';
                break;
            case 2:
                $imgCreate = imagecreatefromjpeg($filename);
                $newImage = $newImage . '.jpg';
                break;
            case 3:
                $imgCreate = imagecreatefrompng($filename);
                $newImage = $newImage . '.png';
                break;
            default:
                return false;
        }

        //获取图2信息
        $water_info = getimagesize($watername);
        $width = $water_info[0]; //图2宽度
        $height = $water_info[1]; //图2高度

        //根据水印图类型加载水印图
        switch($water_info[2]){
            case 1:
                $waterCreate = imagecreatefromgif($watername);
                break;
            case 2:
                $waterCreate = imagecreatefromjpeg($watername);
                break;
            case 3:
                $waterCreate = imagecreatefrompng($watername);
                break;
            default:
                return false;
        }

        if($width>$w/2 || $height>$h/2){
            //水印图宽高超过原图一半，则无法生成水印！
            return false;
        }

        //定位水印图在原图中的位置
        switch($offset){
            case 0://随机
                $posX = rand(0,($w - $width));
                $posY = rand(0,($h - $height));
                break;
            case 1://1为顶端居左
                $posX = 0;
                $posY = 0;
                break;
            case 2://2为顶端居中
                $posX = ($w - $width) / 2;
                $posY = 0;
                break;
            case 3://3为顶端居右
                $posX = $w - $width;
                $posY = 0;
                break;
            case 4://4为中部居左
                $posX = 0;
                $posY = ($h - $height) / 2;
                break;
            case 5://5为中部居中
                $posX = ($w - $width) / 2;
                $posY = ($h - $height) / 2;
                break;
            case 6://6为中部居右
                $posX = $w - $width;
                $posY = ($h - $height) / 2;
                break;
            case 7://7为底端居左
                $posX = 0;
                $posY = $h - $height;
                break;
            case 8://8为底端居中
                $posX = ($w - $width) / 2;
                $posY = $h - $height;
                break;
            case 9://9为底端居右
                $posX = $w - $width;
                $posY = $h - $height;
                break;
            default://自定义
                $posX = rand(0,($w - $width));
                $posY = rand(0,($h - $height));
                break;
        }

        //设定图像的混色模式
        imagealphablending($imgCreate, true);
        //拷贝水印到目标文件
        imagecopymerge($imgCreate, $waterCreate, $posX, $posY, 0, 0, $width,$height,$opacity);

        //生成加了水印的图片，如果$path为空则覆盖原图
        switch($img_info[2]){
            case 1:
                if(empty($path))
                    imagegif($imgCreate, $filename);
                else
                    imagegif($imgCreate, $path.$newImage);
                break;
            case 2:
                if(empty($path))
                    imagejpeg($imgCreate, $filename);
                else
                    imagejpeg($imgCreate, $path.$newImage);
                break;
            case 3:
                if(empty($path))
                    imagepng($imgCreate, $filename);
                else
                    imagepng($imgCreate, $path.$newImage);
                break;
        }
        imagedestroy($imgCreate);
        //如果$path为空则输出true，否则输出新的水印图名称
        return empty($path) ? true : $newImage;
    }

    /**
     * pictureMerge 图片合成
     * @param $filename 被合并的图
     * @param $watername 合并的图
     * @param int $x 合并图在被合并的图的x坐标
     * @param int $y 合并图在被合并的图的y坐标
     * @param string $path 合并后的图片路径
     * @param int $offset
     * @param int $opacity
     * @return bool|string
     * @author Fizz
     * @time 2018.
     */
    function pictureMerge($filename, $watername, $path = '', $x = 0, $y = 0, $offset = 9,$opacity=100){
        //获取原图信息
        $img_info = getimagesize($filename);
        $w = $img_info[0]; //原图宽度
        $h = $img_info[1]; //原图高度

        //定义新图片名称
//        $newImage = 'thumb_' . date('YmdHis') . rand(1000,9999);

        //根据原图类型加载原图
        switch($img_info[2]){
            case 1:
                $imgCreate = imagecreatefromgif($filename);
                $newImage = '.gif';
                break;
            case 2:
                $imgCreate = imagecreatefromjpeg($filename);
                $newImage = '.jpg';
                break;
            case 3:
                $imgCreate = imagecreatefrompng($filename);
                $newImage = '.png';
                break;
            default:
                return false;
        }

        //获取图2信息
        $water_info = getimagesize($watername);
        $width = $water_info[0]; //图2宽度
        $height = $water_info[1]; //图2高度

        //根据水印图类型加载水印图
        switch($water_info[2]){
            case 1:
                $waterCreate = imagecreatefromgif($watername);
                break;
            case 2:
                $waterCreate = imagecreatefromjpeg($watername);
                break;
            case 3:
                $waterCreate = imagecreatefrompng($watername);
                break;
            default:
                return false;
        }

        /**
         *  bool imagecopyresampled ( resource $dst_image , resource $src_image , int $dst_x , int $dst_y , int $src_x , int $src_y , int $dst_w , int $dst_h ,int $src_w , int $src_h )

        $dst_image：新建的图片

        $src_image：需要载入的图片

        $dst_x：设定需要载入的图片在新图中的x坐标

        $dst_y：设定需要载入的图片在新图中的y坐标

        $src_x：设定载入图片要载入的区域x坐标

        $src_y：设定载入图片要载入的区域y坐标

        $dst_w：设定载入的原图的宽度（在此设置缩放）

        $dst_h：设定载入的原图的高度（在此设置缩放）

        $src_w：原图要载入的宽度

        $src_h：原图要载入的高度
         */
        imagecopyresampled($imgCreate, $waterCreate, $x, $y, 0, 0,imagesx($imgCreate),imagesy($imgCreate),imagesx($imgCreate),imagesy($imgCreate));
//        imagecopyresampled($image, $imgCreate, 0, 0, 0, 0,imagesx($imgCreate),imagesy($imgCreate),imagesx($imgCreate),imagesy($imgCreate));

        //生成合并后的图片，如果$path为空则覆盖原图
        switch($img_info[2]){
            case 1:
                if(empty($path))
                    imagegif($imgCreate, $imgCreate);
                else
                    imagegif($imgCreate, $path.$newImage);
                break;
            case 2:
                if(empty($path))
                    imagejpeg($imgCreate, $imgCreate);
                else
                    imagejpeg($imgCreate, $path.$newImage);
                break;
            case 3:
                if(empty($path))
                    imagepng($imgCreate, $imgCreate);
                else
                    imagepng($imgCreate, $path.$newImage);
                break;
        }
        imagedestroy($imgCreate);
        //如果$path为空则输出true，否则输出新的水印图名称
        return empty($path) ? true : $path.$newImage;
    }

    /**
     * 字体写入图片
     * @param Int $width 图片宽度
     * @param Int $height 图片高度
     * @param Int $codelen 字符数量
     * @param String $font 指定字体
     */
    function ValidateCode($width = 90, $height = 26, $codelen = 4, $font = 'invitation.ttf',$text = '我是德玛西亚'){
        $font = public_path().'/share/song.ttf';

        $code = '';//验证码
        $fontsize = 14;//指定字体大小

        //生成随机颜色的背景
        $img = imagecreatetruecolor($width, $height); //生成画布
        //生成颜色
        $color = imagecolorallocate($img, mt_rand(150,250), mt_rand(150,250), mt_rand(150,250));
        //矩形填充
        imagefilledrectangle($img,0,0,$width,$height,$color);

        //生成文字
        $_x = $width / $codelen; //规定每个文字的绘制区间
        for ($i=0;$i<$codelen;$i++) {
            //生成每一个文字的颜色
            $fontcolor = imagecolorallocate($img,mt_rand(0,100),mt_rand(0,100),mt_rand(0,100));
            //填充文字
            imagettftext($img,$fontsize,mt_rand(-30,30),$_x*$i+mt_rand(1,5),$height / 1.4,$fontcolor,$font,$text);
        }

        //设置图片头部并输出png图片数据流
        header('Content-type:image/png');
        imagepng($img);
        imagedestroy($img);

        //返回验证字符  strtolower()全部把字符串转化小写 strtouper()全部转成大写
        return strtolower($code);
    }

    public function textImages($tu,$height,$size,$text,$num){
        $font = public_path().'/sharePicture/song.ttf';
        $imagestu = imagecreatefromstring(file_get_contents($tu));
        if($num == 1){
            $black = imagecolorallocate($imagestu, 72, 63, 42);//字体颜色RGB
        }else{
            $black = imagecolorallocate($imagestu, 202, 204, 207);//字体颜色RGB
        }

        //计算大图宽度
        $ext     = pathinfo($tu);
        $src_img = null;
        switch ($ext['extension']) {
            case 'jpg':
                $src_img = imagecreatefromjpeg($tu);
                break;
            case 'png':
                $src_img = imagecreatefrompng($tu);
                break;
        }
        $wh  = getimagesize($tu);
        $w   = $wh[0];//图的宽度

        $strlen = mb_strlen ( $text );
        if ($strlen > 12) {
            $text = mb_substr ($text, 0, 9).'...，';
        } else {
            $text .= '，';
        }

        //图片大小是750px*1334px,文字要居中
        $imagewh = imagettfbbox($size,0,$font,$text);
        $textWidth = $imagewh[2] - $imagewh[0];

        $x = (($w - $textWidth) / 2);//计算文字的水平位置

        if ($strlen <= 12) $x -= 4;

        imagefttext($imagestu, $size, 0, $x, $height + ($imagewh[1] - $imagewh[7])/2, $black, $font, $text);

        imagepng($imagestu, $tu);

        //释放图片资源
        imagedestroy($imagestu);

        return $tu;
    }
}