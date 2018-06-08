<?php
/**
 * 解析电子书抽象类
 * @author: pyh
 * @time: 2017/10/23
 */
namespace Someline\Tool\Admin\Epub;


use Illuminate\Support\Facades\DB;
use phpQueryObject;
use Someline\Models\Common\Book;
use Someline\Models\Common\BookCatalogue;
use Someline\Models\Common\BookContent;
use Someline\Tool\Oss;

class parseEpub
{
	protected static $_instance = null;

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
		}
		return self::$_instance;
	}

	/**
	 * 解析电子书
	 * @param string $path 文件路径
	 * @param Book $book_model 文件表模型
	 * @return bool
	 * @author: pyh
	 * @time: 2017/10/23
	 */
	public function parse($path,$book_model)
	{
		try {
			DB::beginTransaction();
			$epub_name = basename($path);
			$epub_dir = dirname($path);
			$unzip_dir = $epub_dir.'/'.$epub_name.'_dir';
			if (!file_exists($unzip_dir)) {
				mkdir($unzip_dir,0777);
			}
			exec("unzip {$path} -d {$unzip_dir}");
			$container_xml_path = $unzip_dir.'/META-INF/'.'container.xml';
			$container_xml_doc = \phpQuery::newDocumentFileXML($container_xml_path);
			$content_opf_path = $container_xml_doc->find('container > rootfiles > rootfile:eq(0)')->attr('full-path');
			$content_opf_path = $unzip_dir.'/'.$content_opf_path;
			$content_opf_xml_doc = \phpQuery::newDocumentFileXML($content_opf_path);
			//目录
			$toc_ncx_path = $content_opf_xml_doc->find('#ncx')->attr('href');
			$toc_ncx_path = dirname($content_opf_path).'/'.$toc_ncx_path;
			$toc_ncx_xml_doc = \phpQuery::newDocumentFileXML($toc_ncx_path);
			//先获取目录信息
		    $book_catalogue = $this->getCatalogues($toc_ncx_xml_doc,$toc_ncx_path);
			//存入目录数据
			foreach ($book_catalogue as $key => $catalogue) {

				$is_anchor = $this->isAnchor($catalogue);
				//有锚点的章节处理
				if ($is_anchor) {
					$book_catalogue_model = $book_model->catalogues()->save(new BookCatalogue([
						'name' => $catalogue['name'],
						'parent_id' => 0,
					]));
					$html_doc = $this->handleHtml($catalogue['content_full_url'],$book_model->id);
					$old_html = $html_doc->html();
					$html_doc->find('body')->html($this->getHasAnchorStartContent($old_html,$catalogue['second'][0]['anchor']));
					$book_catalogue_model->content()->save(new BookContent([
						'book_id' => $book_model->id,
						'content' => $this->handleSingleTag($html_doc->html()),
						'anchor' => $catalogue['anchor'],
					]));
					$book_catalogue[$key]['catalogue_id'] = $book_catalogue_model->id;
					if (empty($catalogue['second'])) continue;
					foreach ($catalogue['second'] as $second_key => $second) {
						$book_catalogue_model = $book_model->catalogues()->save(new BookCatalogue([
							'name' => $second['name'],
							'parent_id' => $book_catalogue[$key]['catalogue_id'],
						]));
						if (isset($second['content_full_url'])) {
							$use_html_doc = $html_doc;
							if (!isset($catalogue['second'][$second_key+1])) {
								$content_html = $this->replaceBody($use_html_doc->html(),$this->getHasAnchorEndContent($old_html,$catalogue['second'][$second_key]['anchor']));
							} else {
								$content_html = $this->replaceBody($use_html_doc->html(),$this->getHasAnchorMiddleContent($old_html,$catalogue['second'][$second_key]['anchor'],$catalogue['second'][$second_key+1]['anchor']));
							}
							$book_catalogue_model->content()->save(new BookContent([
								'book_id' => $book_model->id,
								'content' => $this->handleSingleTag($content_html),
								'anchor' => $second['anchor'],
							]));
						}
						$book_catalogue[$key]['second'][$second_key]['catalogue_id'] = $book_catalogue_model->id;
					}
					continue;
				}
				//没有锚点处理
				$book_catalogue_model = $book_model->catalogues()->save(new BookCatalogue([
					'name' => $catalogue['name'],
					'parent_id' => 0,
				]));
				if (isset($catalogue['content_full_url'])) {
					$html_doc = $this->handleHtml($catalogue['content_full_url'],$book_model->id);
					$book_catalogue_model->content()->save(new BookContent([
						'book_id' => $book_model->id,
						'content' => $this->handleSingleTag($html_doc->html()),
						'anchor' => $catalogue['anchor'],
					]));
				}

				$book_catalogue[$key]['catalogue_id'] = $book_catalogue_model->id;
				if (empty($catalogue['second'])) continue;
				foreach ($catalogue['second'] as $second_key => $second) {
					$book_catalogue_model = $book_model->catalogues()->save(new BookCatalogue([
						'name' => $second['name'],
						'parent_id' => $book_catalogue[$key]['catalogue_id'],
					]));
					if (isset($second['content_full_url'])) {
						$html_doc = $this->handleHtml($second['content_full_url'],$book_model->id);
						$book_catalogue_model->content()->save(new BookContent([
							'book_id' => $book_model->id,
							'content' => $this->handleSingleTag($html_doc->html()),
							'anchor' => $second['anchor'],
						]));
					}
					$book_catalogue[$key]['second'][$second_key]['catalogue_id'] = $book_catalogue_model->id;
				}
			}
			delAllFile($unzip_dir);
			DB::commit();
			return true;
		} catch (\Exception $exception) {
			DB::rollback();
			return $exception->getMessage();
		}
	}
	/**
	 * 处理单标签
	 * @param string $html
	 * @return string
	 * @author: pyh
	 * @time: 2017/10/26
	 */
	protected function handleSingleTag($html)
	{
		$pattern = "/<([title]*)\/>/is";
		$new_html = preg_replace_callback($pattern,function ($matches) {
			return "<{$matches[1]}></{$matches[1]}>";
		},$html);
		return $new_html;
	}
	/**
	 * 处理html内容
	 * @param string $html_path
	 * @param int $bool_id
	 * @return phpQueryObject|QueryTemplatesSource|QueryTemplatesParse|QueryTemplatesSourceQuery
	 * @throws \Exception
	 * @author: pyh
	 * @time: 2017/10/23
	 */
	public function handleHtml($html_path, $bool_id)
	{
		$html_doc = \phpQuery::newDocumentFileXHTML($html_path);
		$html_doc = $this->handleImg($html_doc,$html_path,$bool_id);
		$html_doc = $this->handleCss($html_doc,$html_path,$bool_id);
		return $html_doc;
	}
	/**
	 * 处理图片
	 * @param phpQueryObject $html_doc
	 * @param string $html_path
	 * @param int $book_id
	 * @return phpQueryObject
	 * @throws \Exception
	 * @author: pyh
	 * @time: 2017/10/24
	 */
	protected function handleImg($html_doc, $html_path, $book_id)
	{
		$img_list = $html_doc->find('img')->get();
		foreach ($img_list as $img) {
			$img_path = dirname($html_path). '/'.$img->getAttribute('src');
			$img_path = urldecode($img_path);
			$upload_result = Oss::instance()->uploadBookFile($book_id,$img_path);
			if (!isset($upload_result['info']['url']))throw new \Exception('上传失败');
			$img->setAttribute('src',$upload_result['info']['url']);
		}
		return $html_doc;
	}

	/**
	 * 处理css
	 * @param phpQueryObject $html_doc
	 * @param string $html_path
	 * @param int $book_id
	 * @return false|phpQueryObject
	 * @throws \Exception
	 * @author: pyh
	 * @time: 2017/10/23
	 */
	protected function handleCss($html_doc, $html_path, $book_id)
	{
		$link_list = $html_doc->find('link')->get();
		foreach ($link_list as $link) {
			$css_path = dirname($html_path). '/'.$link->getAttribute('href');
			$this->handleCssUrl($css_path,$book_id);
			$upload_result = Oss::instance()->uploadBookFile($book_id,$css_path);
			if (!isset($upload_result['info']['url'])) throw new \Exception('上传失败');
			$link->setAttribute('href',$upload_result['info']['url']);
		}
		return $html_doc;
	}
	/**
	 * 处理css_url
	 * @param string $css_path
	 * @param int $book_id
	 * @return true
	 * @throws \Exception
	 * @author: pyh
	 * @time: 2017/10/24
	 */
	protected function handleCssUrl($css_path, $book_id)
	{
		$css_text = file_get_contents($css_path);
		$new_css_text = preg_replace_callback('/(?is)(?<=url\()[^)]+(?=\))/',function ($matches) use ($css_path,$book_id) {
			$is_point = false;
			if (strpos($matches[0],'"') !== false || strpos($matches[0],"'") !== false) {
				$replace_path = trim($matches[0],'"\'');
				$is_point = true;
			} else {
				$replace_path = $matches[0];
			}
			$css_dir = dirname($css_path);
		   	$file_path = $css_dir.'/'.$replace_path;
		   	if (file_exists($file_path)) {
				$upload_result = Oss::instance()->uploadBookFile($book_id,$file_path);
				if (!isset($upload_result['info']['url'])) throw new \Exception('上传失败');
				if ($is_point) {
					return '"'.$upload_result['info']['url'].'"';
				} else {
					return $upload_result['info']['url'];
				}
			} else {
				return $matches[0];
			}
		},$css_text);
		if (file_put_contents($css_path,$new_css_text) === false) throw new \Exception('保存失败');
		return true;
	}
	/**
	 * 获取目录信息
	 * @param phpQueryObject $toc_ncx_xml_doc
	 * @param string $toc_ncx_path
	 * @return array
	 * @author: pyh
	 * @time: 2017/10/26
	 */
	protected function getCatalogues($toc_ncx_xml_doc,$toc_ncx_path)
	{
		$map_list = $toc_ncx_xml_doc->find("navMap > navPoint")->get();
		//先获取目录信息
		$book_catalogue = [];
		foreach ($map_list as $key => $map) {
			$name = $map->getElementsByTagName('navLabel')->item(0)->getElementsByTagName('text')->item(0)->firstChild->wholeText;
			$book_catalogue[$key]['name'] = $name;
			if ($map->getElementsByTagName('content')->length > 0) {
				$content_full_url = $map->getElementsByTagName('content')->item(0)->getAttribute('src');
				if (strpos($content_full_url,'#') === false) {
					$book_catalogue[$key]['anchor'] = '';
					$book_catalogue[$key]['content_match_url'] = $content_full_url;
				} else {
					$book_catalogue[$key]['anchor'] = substr($content_full_url,strpos($content_full_url,'#')+1);
					$book_catalogue[$key]['content_match_url'] = substr($content_full_url,0,strpos($content_full_url,'#'));
				}
				$book_catalogue[$key]['content_full_url'] = dirname($toc_ncx_path).'/'.$book_catalogue[$key]['content_match_url'];
			}
			$map_second_list = $toc_ncx_xml_doc->find("navMap > navPoint:eq({$key}) > navPoint")->get();
			if (empty($map_second_list)) {
				$book_catalogue[$key]['second'] = [];
				continue;
			}
			foreach ($map_second_list as $second_key => $map_second) {
				$name = $map_second->getElementsByTagName('navLabel')->item(0)->getElementsByTagName('text')->item(0)->firstChild->wholeText;
				$book_catalogue[$key]['second'][$second_key]['name'] = $name;
				if ($map_second->getElementsByTagName('content')->length > 0) {
					$content_full_url = $map_second->getElementsByTagName('content')->item(0)->getAttribute('src');
					if (strpos($content_full_url,'#') === false) {
						$book_catalogue[$key]['second'][$second_key]['anchor'] = '';
						$book_catalogue[$key]['second'][$second_key]['content_match_url'] = $content_full_url;
					} else {
						$book_catalogue[$key]['second'][$second_key]['anchor'] = substr($content_full_url,strpos($content_full_url,'#')+1);
						$book_catalogue[$key]['second'][$second_key]['content_match_url'] = substr($content_full_url,0,strpos($content_full_url,'#'));
					}
					$book_catalogue[$key]['second'][$second_key]['content_full_url'] = dirname($toc_ncx_path).'/'.$book_catalogue[$key]['second'][$second_key]['content_match_url'];
				}
			}
		}
		return $book_catalogue;
	}
	/**
	 * 判断是否有锚点章节
	 * @param array $catalogue 章节信息
	 * @return bool
	 * @author: pyh
	 * @time: 2017/10/26
	 */
	public function isAnchor($catalogue)
	{
		if ($catalogue['anchor']) return true;
		foreach ($catalogue['second'] as $second) {
			if ($second['anchor']) return true;
		}
		return false;
	}
	/**
	 * 获取书籍开头的节信息
	 * @param string $html 文本信息
	 * @param string $start_anchor 开始锚点
	 * @param int $level 章节层级 1:章 2:节
	 * @return string
	 * @author: pyh
	 * @time: 2017/10/26
	 */
	public function getHasAnchorStartContent($html,$start_anchor,$level = 2)
	{
		$pattern = "/<body[^>]*>(.*?)<h{$level}[^>]*?id=\"{$start_anchor}\"[^>]*>/is";
		preg_match($pattern,$html,$result);
		return $result[1]??'';

	}
	/**
	 * 获取书籍中间的章节信息
	 * @param string $html 文本信息
	 * @param string $start_anchor 开始锚点
	 * @param string $end_anchor 结束锚点
	 * @param int $level 章节层级 1:章 2:节
	 * @return string
	 * @author: pyh
	 * @time: 2017/10/26
	 */
	public function getHasAnchorMiddleContent($html,$start_anchor,$end_anchor,$level = 2)
	{
		$pattern = "/(<h{$level}[^>]*?id=\"{$start_anchor}\"[^>]*>.*?)<h{$level}[^>]*?id=\"{$end_anchor}\"[^>]*>/is";
		preg_match($pattern,$html,$result);
		return $result[1]??'';
	}
	/**
	 * 获取书籍结尾的章节信息
	 * @param string $html 文本信息
	 * @param string $end_anchor 结束锚点
	 * @param int $level 章节层级 1:章 2:节
	 * @return string
	 * @author: pyh
	 * @time: 2017/10/26
	 */
	public function getHasAnchorEndContent($html,$end_anchor,$level = 2)
	{
		$pattern = "/(<h{$level}[^>]*?id=\"{$end_anchor}\"[^>]*>.*?)<\/body[^>]*>/is";
		preg_match($pattern,$html,$result);
		return $result[1]??'';
	}

	/**
	 * body替换
	 * @param string $html 文件
	 * @param string $body body 体
	 * @return string
	 * @author: pyh
	 * @time: 2017/11/2
	 */
	public function replaceBody($html,$body)
	{
		$pattern = "/<body>(.*?)<\/body>/is";
		return preg_replace($pattern,"<body>".$body."</body>",$html,1);
	}





}