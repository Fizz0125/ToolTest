<?php
/**
 * ffmpeg 操作工具类
 * @author: pyh
 * @time: 2017/9/29
 */

namespace Someline\Tool\FFMpeg;


use FFMpeg\FFProbe;

class FFMpeg
{
	/**
	 * @var 实例对象
	 * @author: pyh
	 * @time: 2017/9/25
	 */
	protected static $_instance = null;
	/**
	 * 获取ffmpegObject
	 * @author: pyh
	 * @time: 2017/9/29
	 */
	protected $ffmpeg = null;
	/**
	 * 获取ffprobe
	 * @author: pyh
	 * @time: 2018/1/12
	 */
	protected $ffprobe = null;
	protected function __clone(){}

	public static function instance()
	{
		if (!self::$_instance) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	protected function __construct()
	{
		$this->ffmpeg = \FFMpeg\FFMpeg::create(array(
			'ffmpeg.binaries'  => env('FFMPEG_FFMPEG_PATH'),
			'ffprobe.binaries' => env('FFMPEG_FFPROBE_PATH'),
			'timeout'          => 3600, // The timeout for the underlying process
			'ffmpeg.threads'   => 12,   // The number of threads that FFMpeg should use
		));
		$this->ffprobe = FFProbe::create(array(
			'ffmpeg.binaries'  => env('FFMPEG_FFMPEG_PATH'),
			'ffprobe.binaries' => env('FFMPEG_FFPROBE_PATH'),
			'timeout'          => 3600, // The timeout for the underlying process
			'ffmpeg.threads'   => 12,   // The number of threads that FFMpeg should use
		));
	}
	/**
	 * 获取ffmpeg
	 * @author: pyh
	 * @time: 2017/9/29
	 */
	public function getFFMpeg()
	{
		return $this->ffmpeg;
	}
	/**
	 * 获取
	 * @author: pyh
	 * @time: 2018/1/12
	 */
	public function getFfprobe()
	{
		return $this->ffprobe;
	}
}