<?php


namespace app\crontab\command;

use alipay\SyncOrder;
use app\fmyadmin\model\Citys;
use app\fmyadmin\model\Districts;
use app\fmyadmin\model\Express;
use app\fmyadmin\model\OauthPlatform;
use app\fmyadmin\model\OauthUser;
use app\fmyadmin\model\Order;
use app\fmyadmin\model\OrderAction;
use app\fmyadmin\model\PromOrderRelation;
use app\fmyadmin\model\Provinces;
use app\fmyadmin\model\SingleSystem;
use app\fmyadmin\model\SingleSystemRelation;
use app\fmyadmin\model\User;
use app\fmyadmin\model\UserHeaderLibrary;
use app\fmyadmin\server\OrderStatisticServer;
use Carbon\Carbon;
use expressDriver\AIGUO\AIGUO;
use order\VerifyOrders;
use Qcloud\Sms\SmsSingleSender;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
use tool\AntRedis;
use app\fmyadmin\model\PromOrderUser as PromOrderUserModel;
use app\fmyadmin\model\PromOrder as PromOrderModel;

/**
 * 获取老系统闲鱼订单（Redis）到新系统
 *
 * Class ScorePool
 * @package app\crontab\command
 */
class SyncOldIdleOrder extends Command
{
    protected $redis;

    /**
     * 配置(描述)
     */
    protected function configure()
    {
        $this->setName('SyncOldIdleOrder')->setDescription("获取老系统闲鱼订单（Redis）到新系统");
    }

    /**
     * 执行
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function execute(Input $input, Output $output)
    {
        set_time_limit(0);

        Log::notice("获取老系统闲鱼订单（Redis）到新系统 Start");

        # 老系统Redis
        $antRedis = new AntRedis(['select' => 4]);
        # 获取数据
        for ($i = 0; $i < 200; $i++) {
            # 订单Json数据
            $paramsStr = $antRedis->rpop('xianyu_push_data');

            Log::notice("获取老系统闲鱼订单【老系统】，Json数据:" . $paramsStr);
            if (empty($paramsStr)) break;
            # 数据备份
            $antRedis->lpush('xianyu_push_data_back_old:' . date('Y-m:d'), $paramsStr);

            $order = json_decode($paramsStr);
            $orderData = json_decode($order->content, true);
            $appKey = $orderData['app_key'];

            if ($order->topic != 'xianyu_recycle_OrderStatusSync') continue;

            # 判断平台是否存在
            $oauthPlatformId = OauthPlatform::where('appid', $appKey)->value('id');
            if (empty($oauthPlatformId)) {
                Log::info("获取闲鱼平台ID失败【老系统】,App Key:" . $appKey);
                continue;
            }

            # 检测用户是否存在
            $userId = OauthUser::checkIdleUser($orderData['seller_alipay_user_id']);
            if (is_null($userId)) {
                # 检测是否存在手机号
                if (!isset($orderData['seller_phone']) || empty($orderData['seller_phone'])) {
                    Log::info("用户手机号不存在或者为空【老系统】,订单数据:" . json_encode($orderData));
                    continue;
                }
                $userId = User::checkUserByPhone($orderData['seller_phone']);
                if (is_null($userId)) {
                    # 新增用户记录
                    $userInfo = User::create([
                        'name' => $orderData['seller_nick'],
                        'head_pic' => UserHeaderLibrary::getHeadPic(), // 默认头像
                        'phone' => $orderData['seller_phone'],
                        'province' => $orderData['province'] ?? "",
                        'city' => $orderData['city'] ?? "",
                        'district' => $orderData['area'] ?? "",
                        'nickname' => $orderData['seller_real_name']
                    ]);
                    $userId = $userInfo['id'];
                }
                # 新增Oauth
                OauthUser::create([
                    'user_id' => $userId,
                    'openid' => $orderData['seller_alipay_user_id'],
                    'oauth' => 'idle',
                    'oauth_platform_id' => $oauthPlatformId,
                    'create_time' => date('Y-m-d H:i:s')
                ]);
            }

            # 订单信息处理
            $orderStatus = $orderData['order_status']; // 订单状态
            $orderSn = $orderData['biz_order_id']; // 交易订单号

            # 执行
            switch ($orderStatus) {
                # 订单创建
                case 1:
                    $this->createOrder($orderData, $userId, $oauthPlatformId);
                    break;
                # 卖家确认交易完成:暂不开放
//                case 4:
//                    break;
                # 卖家关闭订单
                case 102:
                    $this->cancelOrder($orderData);
                    break;
                default:
                    Log::info("其他订单状态码【老系统】:【" . $orderStatus . "】，暂不接受，订单号:【" . $orderSn . "】");
            }
        }

        Log::notice("获取老系统闲鱼订单（Redis）到新系统 End");
    }

    /**
     * 创建订单
     *
     * @param array $orderData
     * @param int $userId
     * @param int $oauthPlatformId
     * @return bool
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function createOrder(array $orderData, int $userId, int $oauthPlatformId)
    {
        # 记录日志
        Log::info("收到创建订单消息【老系统】，数据:" . json_encode($orderData, JSON_UNESCAPED_UNICODE));

        # 检测订单号是否已存在
        $order = Order::where('order_sn', $orderData['biz_order_id'])->find();
        if (!empty($order)) {
            Log::warning("订单号【" . $orderData['biz_order_id'] . "】重复 ");
            return false;
        }

        # 预约时间处理
        list($orderDate, $orderStartTime) = explode(" ", $orderData['ship_time']);
        $orderStartTime = substr($orderStartTime, 0, 5);
        $orderEndTime = "23:59";

        # 省 直辖市处理
        $province = $orderData['province'] ?? "";
        $provinceAdcode = !empty($province) ? (Provinces::where('name', $province)->value('adcode') ?? 0) : 0;

        # 市 市辖区处理
        $city = $orderData['city'] ?? "";
        $cityAdcode = !empty($city) ? (Citys::where('name', $city)->value('adcode') ?? 0) : 0;

        # 区/县 处理
        $districtAdcode = isset($orderData['area']) ? (Districts::where('name', $orderData['area'])->value('adcode') ?? 0) : 0;

        # 渠道内的业务数据
        $channelData = json_decode($orderData['channel_data'], true); // PS:区别于善行闲鱼

        # 预估重量
        $estimateWeight = isset($channelData['weight']) ? Order::idleEstimateWeight($channelData['weight']) : 4;

        # 订单类型
        $orderType = isset($channelData['sceneType']) && strtoupper($channelData['sceneType']) == 'LOWVALUE' ? Order::ORDER_TYPE_OB : Order::ORDER_TYPE_OC;

        # 二级渠道
        $channel = $orderData['channel'] ?? "";

        # 三级渠道(飞蚂蚁自定义渠道)
        $subChannel = $channelData['subChannel'] ?? "";

        # 子渠道处理
        if (empty($channel)) {
            $channelChild = $subChannel;
        } else {
            if (!empty($subChannel)) {
                $channelChild = $channel . "-" . $subChannel;
            } else {
                $channelChild = $channel;
            }
        }

        # 创建
        $newOrder = Order::create([
            'order_sn' => $orderData['biz_order_id'],
            'order_type' => $orderType,
            'order_date' => $orderDate,
            'order_start_time' => $orderStartTime,
            'order_end_time' => $orderEndTime,
            'estimate_weight' => $estimateWeight,
            'status' => Order::ORDER_STATUS_1,
            'user_id' => $userId,
            'user_address_id' => 0,
            'user_name' => $orderData['seller_real_name'],
            'user_phone' => $orderData['seller_phone'],
            'user_province' => $province,
            'user_city' => $city,
            'user_district' => $orderData['area'] ?? "",
            'user_street' => "",
            'user_address' => $orderData['seller_address'],
            'channel' => 2, // 默认闲鱼
            'channel_child' => $channelChild ?: "idle",
            'oauth_platform_id' => $oauthPlatformId,
            'province_code' => $provinceAdcode,
            'city_code' => $cityAdcode,
            'district_code' => $districtAdcode
        ]);

        # 判断闲鱼二级或三级渠道是否参加活动
        if (!empty($promOrderId = PromOrderRelation::where([
            ['platform_id', '=', $oauthPlatformId],
            ['channel_child', '=', $channelChild]
        ])->value('prom_order_id'))) {
            $promOrder = PromOrderModel::find($promOrderId);
            # 新增参与记录
            PromOrderUserModel::create([
                'prom_order_id' => $promOrderId,
                'order_id' => $newOrder->id,
                'user_id' => $userId,
                'limit_number' => $promOrder->rule->limit_number,
                'status' => PromOrderUserModel::STATUS_CREATED,
                'expi_time' => Carbon::now()->addDays($promOrder->rule->order_succeed_expi)->toDateTimeString(),
                'create_time' => Carbon::now()->toDateTimeString(),
                'update_time' => Carbon::now()->toDateTimeString(),
                'rule_id' => $promOrder->other_id
            ]);
        }

        # 统计
        OrderStatisticServer::createStatistic($newOrder->order_date, $oauthPlatformId, $orderType);

        # 记录日志
        OrderAction::storeOrderActionLog([
            'order_id' => $newOrder->id,
            'action_type' => 1,
            'action_user' => 0,
            'start_order_status' => 1,
            'end_order_status' => 1,
            'action_note' => "下单成功",
            'status_desc' => "等待确认->等待确认"
        ]);

        # 闲鱼地址校验
        $check = $this->checkIdleAddress($orderData['biz_order_id'], $provinceAdcode);
        if ($check === false) {
            # 发送短信
            $smsResult = $this->sendIdleAddressCheckFailSms($orderData['seller_phone'], '536634');
            Log::notice("闲鱼地址校验【老系统】，订单号:" . $orderData['biz_order_id'] . ",短信发送结果:【" . $smsResult . "】");
        }

        # 检测是否满足验证规则
        if ($newOrder->verifyOrder() === false) {
            # 统计
            OrderStatisticServer::preCancelStatistic($newOrder->order_date, $newOrder->oauth_platform_id, $newOrder->order_type);
            # 设置预约取消
            $newOrder = $newOrder->setPreCancelStatus();
            $newOrder = $newOrder->setAdminRemark("不满足订单验证规则");
            # 记录日志
            OrderAction::storeOrderActionLog([
                'order_id' => $newOrder->id,
                'action_type' => 1,
                'action_user' => 0,
                'start_order_status' => 1,
                'end_order_status' => 7,
                'action_note' => $newOrder->verify->getErrMsg(),
                'status_desc' => "等待确认->预约取消"
            ]);
            # 执行订单取消回调事件
            (new VerifyOrders($newOrder))->orderCancel();

            return true;
        }

        # 判断是否需要同步到支付宝订单中心
        if ($channel == "xyxcy") {
            $materialIdArr = Order::getMaterialId();
            $materialId = array_key_exists($newOrder->order_type, $materialIdArr) ? $materialIdArr[$newOrder->order_type] : $materialIdArr[1];
            $syncOrder = new SyncOrder($newOrder, SyncOrder::ORDER_TYPE_RECOVERY, SyncOrder::OPERATE_INIT, ['merchant_order_status' => SyncOrder::MERCHANT_PREORDER_SUCCESS, 'merchant_order_link_page' => '/pages/myRecyDetails/myRecyDetails'], ['merchant_item_link_page' => '/pages/myRecyDetails/myRecyDetails', 'image_material_id' => $materialId]);
            $result = $syncOrder->exec();
            if ($result === false) {
                $note = "同步失败【初始化】," . $syncOrder->getErrMsg();
            } else {
                $note = "同步成功【初始化】";
            }

            # 记录日志
            OrderAction::storeOrderActionLog([
                'order_id' => $newOrder->id,
                'action_type' => 1,
                'action_user' => -2,
                'start_order_status' => 1,
                'end_order_status' => 1,
                'action_note' => $note,
                'status_desc' => ""
            ]);
        }

        # 添加Redis
        $newOrder->storeOrderRedis();

        # 记录日志
        Log::info("创建订单完成【老系统】,订单号:【" . $orderData['biz_order_id'] . "】");

        return true;
    }

    /**
     * 取消订单
     *
     * @param array $orderData
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function cancelOrder(array $orderData)
    {
        # 记录日志
        Log::info("收到取消订单消息【老系统】，数据:" . json_encode($orderData, JSON_UNESCAPED_UNICODE));

        # 检测订单是否存在
        $order = Order::where('order_sn', $orderData['biz_order_id'])->find();
        if (empty($order)) {
            Log::warning("订单获取失败，订单号【" . $orderData['biz_order_id'] . "】");

            return false;
        }

        # 检测订单状态
        if (!in_array($order->status, [1])) { // 等待确认，其他状态暂时不让取消
            Log::warning("当前订单不满足取消条件【老系统】，订单号【" . $orderData['biz_order_id'] . "】");

            # 记录日志
            OrderAction::storeOrderActionLog([
                'order_id' => $order->id,
                'action_type' => 1,
                'action_user' => 0,
                'start_order_status' => $order->status,
                'end_order_status' => $order->status,
                'action_note' => "闲鱼用户取消非等待确认状态订单【老】，被拒绝",
                'status_desc' => "闲鱼用户取消非等待确认状态订单【老】，被拒绝"
            ]);

            return false;
        }

        # 删除redis
        $order->destroyOrderRedis();

        # 统计
        OrderStatisticServer::preCancelStatistic($order->order_date, $order->oauth_platform_id, $order->order_type);

        # 记录日志
        OrderAction::storeOrderActionLog([
            'order_id' => $order->id,
            'action_type' => 1,
            'action_user' => 0,
            'start_order_status' => 1,
            'end_order_status' => 7,
            'action_note' => "闲鱼用户取消订单【老系统】",
            'status_desc' => "等待确认->预约取消"
        ]);

        # 取消
        $order->status = 7; // 预约取消
        if (!$order->save()) return false;

        # 检测是否分配快递公司(爱裹)
        if (!empty($order->shipping_id)) {
            $express = Express::find($order->shipping_id);
            if (!empty($express) && (strtoupper(explode('-', $express->code)[0]) == "AIGUO")) {
                try {
                    (new AIGUO($express))->cancelExpressOrder($order, "闲鱼用户主动取消", 0);
                } catch (\Exception $exception) {
                    Log::warning("闲鱼订单ID【" . $order->id . "】向爱裹取消快递失败");
                }
            }
        }

        # 记录日志
        Log::info("卖家取消订单完成【老系统】,订单号:【" . $orderData['biz_order_id'] . "】");

        return true;
    }

    /**
     * 闲鱼地址校验
     *
     * @param string $orderSn
     * @param string $provinceCode
     * @param string $orderType
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function checkIdleAddress(string $orderSn, string $provinceCode = '0', string $orderType = '1')
    {
        # 应用匹配
        $systemId = SingleSystem::where([
            ['type', '=', $orderType],
            ['is_enable_default', '=', 1] # 默认
        ])->value('id');
        if (empty($systemId)) {
            Log::notice("闲鱼地址校验失败【老系统】，原因:【" . $orderSn . "】应用匹配失败");
            return false;
        }
        # 不存在省直接反馈不支持
        if (empty($provinceCode)) {
            Log::notice("闲鱼地址校验失败【老系统】，原因:【" . $orderSn . "】不存在省！");
            return false;
        }
        $province = SingleSystemRelation::getOne($systemId, $provinceCode, 1);
        # 存在省且未开通
        if (!empty($province) && $province->status != 1) {
            Log::notice("闲鱼地址校验失败【老系统】，原因:【" . $orderSn . "】存在省，但处于关闭状态！");
            return false;
        }

        return true;
    }

    /**
     * 发送闲鱼地址校验短信
     *
     * @param string $phone
     * @param string $id
     * @return bool
     */
    private function sendIdleAddressCheckFailSms(string $phone, string $id)
    {
        $sms = new SmsSingleSender(config('sms_app_id'), config('sms_app_key'));
        $result = $sms->sendWithParam('86', $phone, $id, []);
        $returnData = json_decode($result, true);
        if (isset($returnData['result']) && $returnData['result'] == 0 && isset($returnData['errmsg']) && strtoupper($returnData['errmsg']) == 'OK') {
            return true;
        }

        return false;
    }
}