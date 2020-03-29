<?php


namespace app\crontab\command;


use api\OrderNotification;
use app\fmyadmin\model\Order;
use app\fmyadmin\model\OrderAction;
use app\fmyadmin\server\OrderStatisticServer;
use Carbon\Carbon;
use order\VerifyOrders;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;
use yushu\YuShu;

//书籍分单
class DistributorBookOrder extends Command
{

    /**
     * 配置
     */
    protected function configure()
    {
        $this->setName('DistributorBookOrder')->setDescription("系统分配书籍订单");
    }

    /**
     * 执行分单
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     */
    protected function execute(Input $input, Output $output)
    {
        # 记录日志
        Log::notice('书籍分单开始');
        # 分单规则分单(读表)
        foreach (Order::where([
            ['order_date', '=', Carbon::now()->toDateString()], // 当天
            ['status', '=', Order::ORDER_STATUS_1], // 等待确认
            //['shipping_task', '=', 0], // 非立即分配快递公司
            ['order_type', '=', Order::ORDER_TYPE_OB], // 书籍回收
            //['app_platform_id', '=', 0], //未分配的(分单后,无论是否成功,该字段都为2)
        ])->whereNull('delete_time')->cursor() as $order) {
            try {
                Log::notice('订单ID:【' . $order->id . "】开始分单");

                # 订单规则验证
//                $verify = $order->verifyOrder();
//                if ($verify === false) {
//                    # 统计
//                    OrderStatisticServer::preCancelStatistic($order->order_date, $order->oauth_platform_id, $order->order_type);
//
//                    # 1.设置为预约取消
//                    $order->setPreCancelStatus();
//
//                    # 2.执行订单取消回调事件
//                    (new VerifyOrders($order))->orderCancel();
//
//                    Log::error("订单ID:【" . $order->id . "】订单验证规则验证失败");
//                    continue;
//                }

                # 下单
                $result = $this->sendOrdersYs($order);

                Log::notice("订单分配渔书结果↓");

                $result = json_decode($result,true);
                
                Log::notice($result);

                //下单成功，更新订单
                if(isset($result['insert_id']) && $result['insert_id'] > 0){
                    //状态
                    $order->status = Order::ORDER_STATUS_2;
                    //第三方平台ID fmy_appoint_platform表
                    $order->app_platform_id = 2;
                    //返回的订单ID
                    $order->child_sn = $result['insert_id'];
                    //备注
                    $order->admin_remark = '下单成功:返回订单号'.$result['insert_id'];
                    $order->save();

                    // Api订单发送"回收中"通知
                    if ($order->oauthPlatform->oauth == 'api') {
                        $notification = new OrderNotification($order);
                        $notification->sendOrder();
                    }

                    # 记录日志
                    Log::notice('订单ID:【' . $order->id . "】".$order->admin_remark);
                }
                //下单失败
                else{

                    $msg = '下单失败:';
                    $msg .= isset($result['errmsg'])?$result['errmsg']:'原因未知';

                    $order->app_platform_id = 2;
                    $order->admin_remark = $msg;
                    $order->update_time = date('Y-m-d H:i:s');
                    $order->save();

                    Log::error("订单ID:【" . $order->id . "】".$msg);
                }
                # 记录订单操作日志
                OrderAction::saveOrderAction($order,Order::ORDER_STATUS_1,$order->admin_remark);

            } catch (\Exception $exception) {
                Log::error('订单ID:【' . $order->id . "】分单失败");
                Log::error("分单失败【" . $order->id . "】数据:" . json_encode([
                        'code' => $exception->getCode(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'message' => $exception->getMessage()
                    ], JSON_UNESCAPED_UNICODE));
                continue;
            }
        }
        # 记录日志
        Log::notice('书籍分单结束');
    }

    /**
     * 分单给渔书
     * @param $order Order
     *
     * @return string
     */
    public function sendOrdersYs($order)
    {
        //组装数据
        $data['book_number'] = 25;//给默认值
        $data['province'] = $order['user_province'];
        $data['city'] = $order['user_city'];
        $data['district'] = $order['user_district'];
        $data['order_date'] = $order['order_date'];
        $data['remark'] = '';
        $data['address'] = $order['user_address'];
        $data['order_no'] = $order['order_sn'];
        $data['user_name'] = $order['user_name'];
        $data['user_phone'] = $order['user_phone'];

        //发送请求
        $api = new YuShu;

        return $api->createOrder($data);
    }


}