<?php
// +----------------------------------------------------------------------
// | 海豚PHP框架 [ DolphinPHP ]
// +----------------------------------------------------------------------
// | 版权所有 2016~2017 河源市卓锐科技有限公司 [ http://www.zrthink.com ]
// +----------------------------------------------------------------------
// | 官方网站: http://dolphinphp.com
// +----------------------------------------------------------------------
// | 开源协议 ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------

namespace plugins\AliyunOss;

use app\common\controller\Plugin;
use app\admin\model\Attachment as AttachmentModel;
use think\Db;
use think\Image;
use OSS\OssClient;
use OSS\Core\OssUtil;
use OSS\Core\OssException;
use think\facade\Env;
require Env::get('root_path').'plugins/AliyunOss/SDK/autoload.php';

/**
 * 阿里云OSS上传插件
 * @package plugins\AliyunOss
 * @author 蔡伟明 <314013107@qq.com>
 */
class AliyunOss extends Plugin
{
    /**
     * @var array 插件信息
     */
    public $info = [
        // 插件名[必填]
        'name'        => 'AliyunOss',
        // 插件标题[必填]
        'title'       => '阿里云OSS上传插件',
        // 插件唯一标识[必填],格式：插件名.开发者标识.plugin
        'identifier'  => 'aliyun_oss.ming.plugin',
        // 插件图标[选填]
        'icon'        => 'fa fa-fw fa-upload',
        // 插件描述[选填]
        'description' => '仅支持DolphinPHP1.0.6以上版本，安装后，需将【<a href="/admin.php/admin/system/index/group/upload.html">上传驱动</a>】将其设置为“阿里云OSS”。在附件管理中删除文件，并不会同时删除阿里云OSS上的文件。',
        // 插件作者[必填]
        'author'      => '蔡伟明',
        // 作者主页[选填]
        'author_url'  => 'http://www.caiweiming.com',
        // 插件版本[必填],格式采用三段式：主版本号.次版本号.修订版本号
        'version'     => '1.1.0',
        // 是否有后台管理功能[选填]
        'admin'       => '0',
    ];

    /**
     * @var array 插件钩子
     */
    public $hooks = [
        'upload_attachment'
    ];

    /**
     * 上传附件
     * @param array $params 参数
     * @author 蔡伟明 <314013107@qq.com>
     * @return mixed
     */
    public function uploadAttachment($params = [])
    {
        $file = $params['file'];
        // 缩略图参数
        $thumb = request()->post('thumb', '');
        // 水印参数
        $watermark = request()->post('watermark', '');

        $config = $this->getConfigValue();

        $error_msg = '';
        if ($config['ak'] == '') {
            $error_msg = '未填写阿里云OSS【AccessKeyId】';
        } elseif ($config['sk'] == '') {
            $error_msg = '未填写阿里云OSS【AccessKeySecret】';
        } elseif ($config['bucket'] == '') {
            $error_msg = '未填写阿里云OSS【Bucket】';
        } elseif ($config['endpoint'] == '') {
            $error_msg = '未填写阿里云OSS【Endpoint】';
        }

        if ($error_msg != '') {
            return $this->errMsg($error_msg);
        }

        // 访问域名
        if ($config['cname'] == 0) {
            $domain   = 'http://'.$config['bucket'].'.'.$config['endpoint'].'/';
            $isCName  = false;
            $endpoint = $config['endpoint'];
        } else {
            $domain   = 'http://'.$config['domain'].'/';
            $isCName  = true;
            $endpoint = $config['domain'];
        }

        // 创建OssClient实例
        try {
            $ossClient = new OssClient($config['ak'], $config['sk'], $endpoint, $isCName);
        } catch (OssException $e) {
            return $this->errMsg($e->getMessage());
        }

        if (is_null($ossClient)) {
            return $this->errMsg('OssClient实例创建失败');
        }

        // 设置时间
//        $ossClient->setTimeout(5184000); // 设置请求超时时间，单位秒，默认是5184000秒, 这里建议 不要设置太小，如果上传文件很大，消耗的时间会比较长
//        $ossClient->setConnectTimeout(10); // 设置连接超时时间，单位秒，默认是10秒

        // 移动到框架应用根目录/uploads/ 目录下
        $info = $file->move(config('upload_path') . DIRECTORY_SEPARATOR . 'temp');
        $file_info = $file->getInfo();

        // 要上传文件的本地路径
        $filePath = $info->getPathname();

        // 上传到阿里云OSS后保存的文件名
        $key = $info->getFilename();
        $ali_oss_prefix = session('ali_oss_prefix');
        if ($ali_oss_prefix) {
            $key = $ali_oss_prefix.$key;
        } else {
            if ($config['dir'] != '' && $config['dir'] != '/') {
                $key = $config['dir'].$key;
            }
        }

        $file_ext = $info->getExtension();
        $ext_limit = config('upload_image_ext');
        $ext_limit = $ext_limit == '' ? [] : explode(',', $ext_limit);
        // 缩略图路径
        $thumb_path_name = '';
        if (preg_grep("/$file_ext/i", $ext_limit)) {
            $img = Image::open($info);
            $img_width  = $img->width();
            $img_height = $img->height();

            // 水印功能
            if ($watermark == '') {
                if (config('upload_thumb_water') == 1 && config('upload_thumb_water_pic') > 0) {
                    $this->create_water($info->getRealPath(), config('upload_thumb_water_pic'));
                }
            } else {
                if (strtolower($watermark) != 'close') {
                    list($watermark_img, $watermark_pos, $watermark_alpha) = explode('|', $watermark);
                    $this->create_water($info->getRealPath(), $watermark_img, $watermark_pos, $watermark_alpha);
                }
            }

            // 生成缩略图
            if ($thumb == '') {
                if (config('upload_image_thumb') != '') {
                    list($thumb_max_width, $thumb_max_height) = explode(',', config('upload_image_thumb'));
                    $thumb_path_name = $domain.$key.'?x-oss-process=image/resize,h_'.$thumb_max_width.',w_'.$thumb_max_height;
                }
            } else {
                if (strtolower($thumb) != 'close') {
                    list($thumb_size, $thumb_type) = explode('|', $thumb);
                    list($thumb_max_width, $thumb_max_height) = explode(',', $thumb_size);
                    $thumb_path_name = $domain.$key.'?x-oss-process=image/resize,h_'.$thumb_max_width.',w_'.$thumb_max_height;
                }
            }
        } else {
            $img_width  = '';
            $img_height = '';
        }

        try {
            $ossClient->multiuploadFile($config['bucket'], $key, $filePath);
        } catch (OssException $e) {
            return $this->errMsg($e->getMessage());
        }

        // 获取附件信息
        $data = [
            'uid'    => session('user_auth.uid'),
            'name'   => $file_info['name'],
            'mime'   => $file_info['type'],
            'path'   => $domain.$key,
            'ext'    => $file_ext,
            'size'   => $info->getSize(),
            'md5'    => $info->hash('md5'),
            'sha1'   => $info->hash('sha1'),
            'thumb'  => $thumb_path_name,
            'module' => $params['module'],
            'driver' => 'aliyunoss',
            'width'  => $img_width,
            'height' => $img_height,
        ];

        if ($file_add = AttachmentModel::create($data)) {
            unset($info);
            // 删除本地临时文件
            @unlink($filePath);
            switch ($params['from']) {
                case 'wangeditor':
                    return $data['path'];
                    break;
                case 'ueditor':
                    return json([
                        "state" => "SUCCESS",          // 上传状态，上传成功时必须返回"SUCCESS"
                        "url"   => $data['path'], // 返回的地址
                        "title" => $file_info['name'], // 附件名
                    ]);
                    break;
                case 'editormd':
                    return json([
                        "success" => 1,
                        "message" => '上传成功',
                        "url"     => $data['path'],
                    ]);
                    break;
                case 'ckeditor':
                    return ck_js(request()->get('CKEditorFuncNum'), $data['path']);
                    break;
                default:
                    return json([
                        'code'   => 1,
                        'info'   => '上传成功',
                        'class'  => 'success',
                        'id'     => $file_add['id'],
                        'path'   => $data['path']
                    ]);
            }
        } else {
            return $this->errMsg('上传失败');
        }
    }


    /**
     * 返回错误信息
     * @param string $error_msg 信息内容
     * @param string $form 来源
     * @author 蔡伟明 <314013107@qq.com>
     * @return string|\think\response\Json
     */
    private function errMsg($error_msg = '', $form = '')
    {
        switch ($form) {
            case 'wangeditor':
                return "error|{$error_msg}";
                break;
            case 'ueditor':
                return json(['state' => $error_msg]);
                break;
            case 'editormd':
                return json(["success" => 0, "message" => $error_msg]);
                break;
            case 'ckeditor':
                return ck_js(request()->get('CKEditorFuncNum'), '', $error_msg);
                break;
            default:
                return json([
                    'code'   => 0,
                    'class'  => 'danger',
                    'info'   => $error_msg
                ]);
        }
    }

    /**
     * 添加水印
     * @param string $file 要添加水印的文件路径
     * @param string $watermark_img 水印图片id
     * @param string $watermark_pos 水印位置
     * @param string $watermark_alpha 水印透明度
     * @author 蔡伟明 <314013107@qq.com>
     */
    private function create_water($file = '', $watermark_img = '', $watermark_pos = '', $watermark_alpha = '')
    {
        $img  = Db::name('admin_attachment')->where('id', $watermark_img)->find();
        $path = $img['path'];
        $tmp  = false;
        if (strtolower(substr($path, 0, 4)) == 'http') {
            $file_watermark  = file_get_contents($path);
            $thumb_water_pic = config('upload_path') . DIRECTORY_SEPARATOR . 'temp/'.$img['md5'].'.'.$img['ext'];
            if (false === file_put_contents($thumb_water_pic, $file_watermark)) {
                return;
            }
            $tmp = true;
        } else {
            $thumb_water_pic = realpath(Env::get('root_path') . 'public/' . $path);
        }

        if (is_file($thumb_water_pic)) {
            // 读取图片
            $image = Image::open($file);
            // 添加水印
            $watermark_pos   = $watermark_pos   == '' ? config('upload_thumb_water_position') : $watermark_pos;
            $watermark_alpha = $watermark_alpha == '' ? config('upload_thumb_water_alpha') : $watermark_alpha;
            $image->water($thumb_water_pic, $watermark_pos, $watermark_alpha);
            // 保存水印图片，覆盖原图
            $image->save($file);
            // 删除临时文件
            if ($tmp) {
                unlink($thumb_water_pic);
            }
        }
    }

    /**
     * 安装方法
     * @author 蔡伟明 <314013107@qq.com>
     * @return bool
     */
    public function install(){
        if (!version_compare(config('dolphin.product_version'), '1.0.6', '>=')) {
            $this->error = '本插件仅支持DolphinPHP1.0.6或以上版本';
            return false;
        }
        $upload_driver = Db::name('admin_config')->where(['name' => 'upload_driver', 'group' => 'upload'])->find();
        if (!$upload_driver) {
            $this->error = '未找到【上传驱动】配置，请确认DolphinPHP版本是否为1.0.6以上';
            return false;
        }
        $options = parse_attr($upload_driver['options']);
        if (isset($options['aliyunoss'])) {
            $this->error = '已存在名为【aliyunoss】的上传驱动';
            return false;
        }
        $upload_driver['options'] .= PHP_EOL.'aliyunoss:阿里云OSS';

        $result = Db::name('admin_config')
            ->where(['name' => 'upload_driver', 'group' => 'upload'])
            ->setField('options', $upload_driver['options']);

        if (false === $result) {
            $this->error = '上传驱动设置失败';
            return false;
        }
        return true;
    }

    /**
     * 卸载方法
     * @author 蔡伟明 <314013107@qq.com>
     * @return bool
     */
    public function uninstall(){
        $upload_driver = Db::name('admin_config')->where(['name' => 'upload_driver', 'group' => 'upload'])->find();
        if ($upload_driver) {
            $options = parse_attr($upload_driver['options']);
            if (isset($options['aliyunoss'])) {
                unset($options['aliyunoss']);
            }
            $options = implode_attr($options);
            $result = Db::name('admin_config')
                ->where(['name' => 'upload_driver', 'group' => 'upload'])
                ->update(['options' => $options, 'value' => 'local']);

            if (false === $result) {
                $this->error = '上传驱动设置失败';
                return false;
            }
        }
        return true;
    }
}