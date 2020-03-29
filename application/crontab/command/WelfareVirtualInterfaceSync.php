<?php


namespace app\crontab\command;

use app\constant\JuheConstant;
use app\constant\NanmuConstant;
use app\fmyadmin\model\WelfareVirtualInterface;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Exception;
use think\facade\Log;

/**
 * 虚拟商品同步(更新虚拟商品)
 *
 * Class WelfareVirtualInterfaceSync
 * @package app\crontab\command
 */
class WelfareVirtualInterfaceSync extends Command
{
    /**
     * 配置(描述)
     */
    protected function configure()
    {
        $this->setName('WelfareVirtualInterfaceSync')->setDescription("虚拟商品同步");
    }

    /**
     * 执行
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     */
    protected function execute(Input $input, Output $output)
    {
        Log::info("虚拟商品同步开始");
        # 同步南木科技 - 视频会员
        $this->syncVideo();
        # 同步聚合 - 流量
        $this->syncFlow();
        # 同步聚合 - 话费
        $this->syncCall();
        Log::info("虚拟商品同步结束");
    }

    /**
     * 同步南木科技 - 视频会员
     */
    private function syncVideo()
    {
        try {
            $postData['merchantId'] = NanmuConstant::SERVICE_SOURCE;
            $postData['timeStamp'] = time();
            $string = 'merchantId=' . $postData['merchantId'] . '&timeStamp=' . $postData['timeStamp'] . '&key=' . NanmuConstant::KEY;
            $sign = md5($string);
            $postData['sign'] = strtoupper($sign);
            $result = WelfareVirtualInterface::sendPost_new(NanmuConstant::URL_NANMU_RECHARGE_INFO, $postData);

            Log::info("同步南木科技【视频会员】接收数据:" . json_encode($result, JSON_UNESCAPED_UNICODE));

            # 验证是否成功
            if (isset($result[0]['code']) && $result[0]['code'] == NanmuConstant::NANMU_OK) {
                # 获取虚拟商品
                $virtualGoodsVideo = WelfareVirtualInterface::get([
                    'service_source' => WelfareVirtualInterface::SERVICE_SOURCE_NANMU,
                    'type' => WelfareVirtualInterface::TYPE_VIDEO
                ]);

                # 参数
                $params['result'] = json_encode($result[0]['products']);
                $params['package_type'] = WelfareVirtualInterface::PACKAGE_TYPE_MOBILE;

                # 存在 - 更新
                if (!empty($virtualGoodsVideo)) {
                    $params['id'] = $virtualGoodsVideo->id;
                    WelfareVirtualInterface::update($params);
                } else {
                    # 不存在 - 新增
                    $params['title'] = "视频会员";
                    $params['type'] = WelfareVirtualInterface::TYPE_VIDEO;
                    $params['service_source'] = WelfareVirtualInterface::SERVICE_SOURCE_NANMU;
                    WelfareVirtualInterface::create($params);
                }

                Log::info("同步南木科技视频会员成功,返回数据:" . json_encode($result, JSON_UNESCAPED_UNICODE));
            } else {
                throw new Exception(json_encode($result, JSON_UNESCAPED_UNICODE));
            }
        } catch (Exception $exception) {
            Log::error("【同步南木科技接口异常】,异常原因:" . $exception->getMessage());
        }
    }

    /**
     * 同步聚合 - 流量
     */
    private function syncFlow()
    {
        try {
            $postData['key'] = JuheConstant::FLOW_KEY;
            $returnData = WelfareVirtualInterface::sendPost_new(JuheConstant::URL_FLOW_LIST, $postData);

            Log::info("同步聚合【流量】接收数据:" . json_encode($returnData, JSON_UNESCAPED_UNICODE));

            # 校验是否成功
            if (isset($returnData[0]['reason']) && $returnData[0]['reason'] == 'success') {
                foreach ($returnData[0]['result'] as $k1 => $v1) {
                    # 参数
                    $params = [
                        'title' => $v1['name'],
                        'type' => WelfareVirtualInterface::TYPE_FLOW,
                        'package_type' => WelfareVirtualInterface::PACKAGE_TYPE_MOBILE,
                        'companytype' => $v1['companytype'],
                        'service_source' => WelfareVirtualInterface::SERVICE_SOURCE_JUHE
                    ];

                    # 数据
                    $result = [];
                    foreach ($v1['flows'] as $k2 => $v2) {
                        # 判断运营商是否废弃部分流量充值套餐
                        if ($this->checkFlowProduct($v1['companytype'], $v2['id']) === false) continue;

                        $result[$k2] = [
                            'product_id' => $v2['id'],
                            'channel_price' => $v2['inprice'],
                            'item_name' => $v2['p'],
                            'original_price' => $v2['inprice'],
                        ];
                    }
                    $params['result'] = json_encode(array_values($result));

                    # 存在 - 更新
                    $virtualGoodsFlow = WelfareVirtualInterface::get([
                        'type' => WelfareVirtualInterface::TYPE_FLOW,
                        'service_source' => WelfareVirtualInterface::SERVICE_SOURCE_JUHE,
                        'package_type' => WelfareVirtualInterface::PACKAGE_TYPE_MOBILE,
                        'companytype' => $v1['companytype']
                    ]);
                    if (!empty($virtualGoodsFlow)) {
                        $params['id'] = $virtualGoodsFlow->id;
                        WelfareVirtualInterface::update($params);
                    } else {
                        # 不存在 - 新增
                        WelfareVirtualInterface::create($params);
                    }
                }

                Log::info("同步聚合流量成功,返回数据:" . json_encode($returnData, JSON_UNESCAPED_UNICODE));
            } else {
                throw new Exception(json_encode($returnData, JSON_UNESCAPED_UNICODE));
            }
        } catch (Exception $exception) {
            Log::error("【同步聚合流量接口异常】,异常原因:" . $exception->getMessage());
        }
    }

    /**
     * 检测是否需要排除运营商不支持的流量套餐
     *
     * @param $type
     * @param $productId
     * @return bool
     */
    private function checkFlowProduct($type, $productId)
    {
        switch ($type) {
            # 移动
            case 2:
                if (in_array($productId, [
                    3, # 10M
                    49, # 100M
                    6, # 150M
                    50, # 300M
                    7, # 500M
                    27 # 2G
                ])) {
                    return false;
                }
                break;
            # 联通
            case 1:
                if (in_array($productId, [
                    34, # 20M
                    1, # 50M
                    37 # 1G
                ])) {
                    return false;
                }
                break;
            # 电信
            case 3:
                if (in_array($productId, [
                    8, # 10M
                    9, # 30M
                    32, # 50M
                    28 # 1G
                ])) {
                    return false;
                }
                break;
            default:
                return false;
        }

        return true;
    }

    /**
     * 同步聚合 - 话费
     */
    private function syncCall()
    {
        try {
            foreach (WelfareVirtualInterface::$telephoneBillType as $k1 => $v1) {
                # 参数
                $params = [
                    'title' => $v1,
                    'type' => WelfareVirtualInterface::TYPE_CALL_CHARGE,
                    'package_type' => WelfareVirtualInterface::PACKAGE_TYPE_MOBILE,
                    'companytype' => $k1,
                    'service_source' => WelfareVirtualInterface::SERVICE_SOURCE_JUHE,
                ];

                # 数据
                $result = [];
                $i = 0;
                foreach (JuheConstant::$telephone_bill as $k2 => $v2) {
                    $result[$i] = [
                        'product_id' => $k2,
                        'channel_price' => $k2,
                        'item_name' => $v2,
                        'original_price' => $k2
                    ];
                    $i++;
                }
                $params['result'] = json_encode($result);

                # 存在 - 更新
                $virtualGoodsCall = WelfareVirtualInterface::get([
                    'type' => WelfareVirtualInterface::TYPE_CALL_CHARGE,
                    'package_type' => WelfareVirtualInterface::PACKAGE_TYPE_MOBILE,
                    'service_source' => WelfareVirtualInterface::SERVICE_SOURCE_JUHE,
                    'companytype' => $k1
                ]);
                if (!empty($virtualGoodsCall)) {
                    $params['id'] = $virtualGoodsCall->id;
                    WelfareVirtualInterface::update($params);
                } else {
                    # 不存在 - 新增
                    WelfareVirtualInterface::create($params);
                }
            }

            Log::info("同步聚合话费成功");
        } catch (Exception $exception) {
            Log::error("【同步聚合话费接口异常】,异常原因:" . $exception->getMessage());
        }
    }
}