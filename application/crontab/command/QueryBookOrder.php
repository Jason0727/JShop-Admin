<?php


namespace app\crontab\command;


use app\fmyadmin\model\Order;
use app\fmyadmin\model\OrderAction;
use app\fmyadmin\server\OrderServer;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Exception;
use think\facade\Log;
use yushu\YuShu;

//书籍查询订单
class QueryBookOrder extends Command
{
    /**
     * 配置
     */
    protected function configure()
    {
        $this->setName('QueryBookOrder')->setDescription("查询书籍订单");
    }

    /**
     * 查询订单
     *
     * @param Input $input
     * @param Output $output
     * @return int|void|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function execute(Input $input, Output $output)
    {
        # 记录日志
        Log::notice('查询书籍订单开始');
        # 查询订单
        foreach (Order::where([
            ['status', '=', Order::ORDER_STATUS_2], // 回收中
            ['order_type', '=', Order::ORDER_TYPE_OB], // 书籍回收
            ['child_sn', '>', 0], // 下单时返回的订单号(查询时该参数必须是整数)

        ])->whereNull('delete_time')->cursor() as $order) {
            try {

                //查询订单
                $api = new YuShu();

                $result = $api->queryOrder($order);

                Log::notice("渔书查询订单结果↓");

                $result = json_decode($result);

                Log::notice($result);

                //无该字段跳过
                if(!isset($result->trace_node)||!$result->trace_node){
                    continue;
                }

                //备注
                if(isset($result->trace_mark) && $result->trace_mark && $result->trace_mark != ''){
                    $remark = $result->trace_mark;
                }else{
                    $remark = $result->status;
                }

                //更新时间
                $update_time = date('Y-m-d H:i:s');

                //订单状态 1,2='回收中' 3,4,5,6='完成' 7,8='取消'
                if(!isset($result->status_num)||!$result->status_num||$result->status_num<1){
                    //返回状态判断
                    switch ($result->trace_node) {

                        case '取件运单妥投':

                            //订单状态
                            $order->status = Order::ORDER_STATUS_3;
                            //备注
                            $order->admin_remark = $remark;
                            //快递单号
                            $order->shipping_su = $result->express_num;
                            //快递公司ID
                            //$order->shipping_id = 39;
                            //快递公司名称(英文简写)
                            $order->shipping_name = 'JD';
                            //更新时间
                            $order->update_time = $update_time;
                            //实际重量 给1kg 配合环保豆发放
                            $order->actual_weight = 1;
                            //保存
                            $order->save();
                            //执行订单完成回调事件
                            (new OrderServer())->orderCompleteFunc($order);

                            break;

                        case '取件终止':

                            $order->status = Order::ORDER_STATUS_7;
                            $order->admin_remark = $remark;
                            $order->update_time = $update_time;
                            $order->save();
                            //执行订单取消回调事件
                            (new OrderServer())->orderCancelFunc($order);

                            break;

                        case '取件完成':

                            $order->status = Order::ORDER_STATUS_3;
                            $order->admin_remark = $remark;
                            $order->shipping_su = $result->express_num;
                            //$order->shipping_id = 39;
                            $order->shipping_name = 'JD';
                            $order->update_time = $update_time;
                            //实际重量 给1kg 配合环保豆发放
                            $order->actual_weight = 1;
                            $order->save();
                            //执行订单完成回调事件
                            (new OrderServer())->orderCompleteFunc($order);

                            break;

                        case '取件单再投':

                            if($order->admin_remark != $remark){
                                $order->admin_remark = $remark;
                                $order->update_time = $update_time;
                                $order->save();
                            }
                            break;

                        default:
                            # code...
                            break;
                    }
                }
                else{
                    //状态信息对照
                    $status_info = [
                        '1'=>Order::ORDER_STATUS_2,
                        '2'=>Order::ORDER_STATUS_2,
                        '3'=>Order::ORDER_STATUS_3,
                        '4'=>Order::ORDER_STATUS_3,
                        '5'=>Order::ORDER_STATUS_3,
                        '6'=>Order::ORDER_STATUS_3,
                        '7'=>Order::ORDER_STATUS_7,
                        '8'=>Order::ORDER_STATUS_7,
                    ];
                    $status = isset($status_info[$result->status_num])?$status_info[$result->status_num]:'';

                    //快递对照
                    $express_info = [
                        'jingdong'  =>  'JD',
                        'deppon'    =>  'DEPPON'
                    ];
                    $express_name = isset($express_info[$result->express_name])?$express_info[$result->express_name]:'';

                    //返回状态判断
                    switch ($status) {

                        //完成
                        case Order::ORDER_STATUS_3:

                            //订单状态
                            $order->status = $status;
                            //备注
                            $order->admin_remark = $remark;
                            //快递单号
                            $order->shipping_su = $result->express_num;
                            //快递公司ID
//                            $order->shipping_id = ;
                            //快递公司名称(英文简写)
                            $order->shipping_name = $express_name;
                            //更新时间
                            $order->update_time = $update_time;
                            //实际重量 给1kg 配合环保豆发放
                            $order->actual_weight = 1;
                            //保存
                            $order->save();
                            //执行订单完成回调事件
                            (new OrderServer())->orderCompleteFunc($order);

                            break;

                        //取消
                        case Order::ORDER_STATUS_7:

                            $order->status = $status;
                            $order->admin_remark = $remark;
                            $order->shipping_name = $express_name;
                            $order->update_time = $update_time;
                            $order->save();
                            //执行订单取消回调事件
                            (new OrderServer())->orderCancelFunc($order);

                            break;

                        //回收中
                        case Order::ORDER_STATUS_2:

                            if($order->admin_remark != $remark){
                                $order->admin_remark = $remark;
                                $order->shipping_name = $express_name;
                                $order->update_time = $update_time;
                                $order->save();
                            }
                            break;

                        default:
                            # code...
                            break;
                    }
                }

                # 记录订单操作日志
                if( $update_time == $order->update_time){
                    OrderAction::saveOrderAction($order,Order::ORDER_STATUS_2,$order->admin_remark);
                }

            } catch (Exception $exception) {
                Log::error("书籍订单查询异常【" . $order->id . "】数据:" . json_encode([
                        'code' => $exception->getCode(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'message' => $exception->getMessage()
                    ], JSON_UNESCAPED_UNICODE));
                continue;
            }
        }
        # 记录日志
        Log::notice('查询书籍订单结束');
    }


}