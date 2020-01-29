<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\AbstractCommissionPool;
use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Events\DeleteOrderSuccEvent;
use StingBo\Mengine\Events\MatchEvent;

class CommissionPoolService extends AbstractCommissionPool
{
    /**
     * 放入委托池.
     */
    public function pushPool(Order $order)
    {
        $ms_service = new MengineService($order);
        if ($ms_service->isHashDeleted($order)) {
            return false;
        }

        $ms_service->deleteHashOrder($order);
        $list = $ms_service->getMutexDepth($order->symbol, $order->transaction, $order->price);
        if ($list) {
            // 撮合
            $order = $this->matchUp($order, $list);
            if (!$order) {
                return false;
            }
        }

        // 深度列表、数量更新、节点更新
        $depth_link = new DepthLinkService();
        $depth_link->pushZset($order);

        $depth_link->pushDepthHash($order);

        $depth_link->pushDepthNode($order);
    }

    /**
     * 撤单从委托池删除.
     */
    public function deletePoolOrder(Order $order)
    {
        $link_service = new LinkService($order->node_link);
        $node = $link_service->getNode($order->node);
        if (!$node) {
            return false;
        }
        if ($node->uuid != $order->uuid) {
            return false;
        }
        if ($node->symbol != $order->symbol) {
            return false;
        }
        if ($node->transaction != $order->transaction) {
            return false;
        }


        // 更新委托量
        $depth_link = new DepthLinkService();

        // order里的volume替换为缓存里节点上的数量,防止order里的数量与当初push的不一致或者部分成交
        $order->volume = $node->volume;
        $depth_link->deleteDepthHash($order);

        // 从深度列表里删除
        $depth_link->deleteZset($order);

        // 从节点链上删除
        $depth_link->deleteDepthNode($order);

        // 撤单成功通知
        event(new DeleteOrderSuccEvent($order));
    }

    /**
     * 撮合.
     *
     * @param object $order 下单
     * @param array  $list  价格匹配部分
     *
     * @return mix
     */
    public function matchUp(Order $order, $list)
    {
        // 撮合
        foreach ($list as $match_info) {
            $link_name = $order->symbol.':link:'.$match_info['price'];
            $link_service = new LinkService($link_name);

            $order = $this->matchOrder($order, $link_service);
            if ($order->volume <= 0) {
                break;
            }
        }

        if ($order->volume > 0) {
            return $order;
        }

        return false;
    }

    public function matchOrder($order, $link_service)
    {
        $match_order = $link_service->getFirst();
        if ($match_order) {
            $compare_result = bccomp($order->volume, $match_order->volume);
            switch ($compare_result) {
                case 1:
                    $match_volume = $match_order->volume;
                    $order->volume = bcsub($order->volume, $match_order->volume);
                    $link_service->deleteNode($match_order);
                    $this->updatePoolOrder($match_order);

                    // 递归撮合
                    $this->matchOrder($order, $link_service);
                    break;
                case 0:
                    $match_volume = $match_order->volume;
                    $order->volume = bcsub($order->volume, $match_order->volume);
                    $link_service->deleteNode($match_order);
                    $this->updatePoolOrder($match_order);
                    break;
                case -1:
                    $match_volume = $order->volume;
                    $match_order->volume = bcsub($match_order->volume, $order->volume);
                    $order->volume = 0;
                    $link_service->setNode($match_order->node, $match_order);

                    // 委托池更新数量重新设置
                    $match_order->volume = $match_volume;
                    $this->updatePoolOrder($match_order);
                    break;
            }

            // 撮合成功通知
            event(new MatchEvent($order, $match_order, $match_volume));

            return $order;
        }

        return $order;
    }

    /**
     * 撮合成交更新委托池.
     */
    public function updatePoolOrder($order)
    {
        $depth_link = new DepthLinkService();

        // 更新委托量
        $depth_link->deleteDepthHash($order);

        // 从深度列表里删除
        $depth_link->deleteZset($order);
    }
}
