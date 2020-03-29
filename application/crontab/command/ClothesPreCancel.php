<?php


namespace app\crontab\command;

use app\fmyadmin\model\Order;
use app\fmyadmin\server\OrderStatisticServer;
use app\fmyadmin\server\TemplateMessageServer;
use Carbon\Carbon;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

class ClothesPreCancel extends Command
{
    /**
     * 配置(描述)
     */
    protected function configure()
    {
        $this->setName('ClothesPreCancel')->setDescription("5kg以下旧衣回收订单取消");
    }

    protected function execute(Input $input, Output $output)
    {
        # 记录日志
        Log::info('5kg以下旧衣回收订单取消开始');
        # 操作
        foreach (Order::where([
            ['order_date', '=', Carbon::now()->toDateString()], // 当天
            ['status', '=', 1], // 等待确认
            ['order_type', '=', 1], // 旧衣回收
            ['estimate_weight', '=', 5], // 5kg以下
        ])->whereNull('delete_time')->cursor() as $order) {
            // 预约取消
            $order = $order->setPreCancelStatus();
            $order = $order->setAdminRemark("订单不满足上门回收条件");
            // 统计
            OrderStatisticServer::preCancelStatistic($order->order_date, $order->oauth_platform_id, $order->order_type);

            // 删除Redis
            $order->destroyOrderRedis();

            // 发送模板消息
            $templateMessage = new TemplateMessageServer();
            $bool = $templateMessage->sendTemplateMessage($order, 'ORDER_CANCEL');
            Log::notice("5kg以下旧衣回收订单取消模板消息发送，订单号【" . $order->order_sn . "】，发送结果【" . $bool . "】");
        }
        # 记录日志
        Log::info('5kg以下旧衣回收订单取消结束');
    }
}