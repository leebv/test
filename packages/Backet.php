<?php
namespace KvaiDjinn;

use Yii;

class Basket {
    const BasketVersion = '20160510.4'; /* for autoclear basket with older versions */
    private static $instance;
    private static $basketID;
    private static $sessID;
    private $basketVersion = self::BasketVersion;
    private $rest_id;
    private $isGroup = false;
    private $groupStatus = 0; /* 0 - edit, 1 - ordering */
    private $coords;
    private $address; /* array [address, full_address] for group orders */
    private $owner;
    private $users = [];
    private $count_products_item = 0;
    private $count_products = 0;
    private $sum_cost_products = 0;
    private $sum_min_products = 0;
    private $sum_cost_products_to_partner = 0;
    private $sum_cost_bonus = 0;
    private $delivery;
    private $delivery_flag = false;
    private $delivery_cost = 0;
    public  $delivery_free = false;
    private $min_sum = 0;
    private $gift = '';
    private $promocode;
    private $total_sum = 0;
    private $total_minus_bonus_sum = 0;
    private $products = [];
    private $bonus_products = [];
    private $active_action;
    private $bonus_active_action;

    private function __construct() {
        $this->owner = self::getUserSessID();
    }

    public static function generateBasketID() {
        return 'o_' . \Yii::$app->getSecurity()->generateRandomString() . '_' . time();
    }

    public static function generateUserSessID() {
        return 'userID_' . \Yii::$app->getSecurity()->generateRandomString() . '_' . time();
    }

    public static function getUserSessID() {
        if (self::$sessID)
            return self::$sessID;

        if ($cookie = \Yii::$app->request->cookies->get('sessID') and $cookie->value)
            return self::$sessID = $cookie->value;

        $sessID = self::generateUserSessID();
        \Yii::$app->response->cookies->add(new \yii\web\Cookie([
                'name' => 'sessID',
                'value' => $sessID,
                'expire' => time() + (365*24*60*60),
            ]));

        return self::$sessID = $sessID;
    }

    public static function checkBasketID($basketID, $restID) {
        $result = true;

        if (($basket = \Yii::$app->cache->get($basketID)) === false)
            $result = false;
        else {
            $currentUser = self::getUserSessID();

            if ($basket['basketVersion'] != self::BasketVersion)
                $result = false;
            elseif ($basket['owner'] != $currentUser) {
                if (!$basket['isGroup'])
                    $result = false;
            }

            if ($result) {
                if ($basket['rest_id'] != $restID)
                    $result = false;
            }
        }

        if (!$result) {
            $session = Yii::$app->session;
            if (!$session->isActive)
                $session->open();
            if ($session->get('basketID') == $basketID)
                $session->remove('basketID');
        }

        return $result;
    }

    public static function setBasketID($basketID = null) {
        if (!$basketID)
            $basketID = self::generateBasketID();
        $session = Yii::$app->session;
        if (!$session->isActive)
            $session->open();

        $session->set('basketID', $basketID);

        return self::$basketID = $basketID;
    }

    public static function resetBasketID() {
        return self::setBasketID();
    }

    public static function getBasketID() {
        return self::$basketID ? self::$basketID : self::resetBasketID();
    }

    public static function getSessID() {
        return self::getUserSessID();
    }

    public static function getBasket() {
        if (!self::$instance) {
            $session = Yii::$app->session;
            if (!$session->isActive)
                $session->open();
            // $basket = isset($_SESSION['basket']) ? unserialize($_SESSION['basket']) : null;
            /* clear old version basket */
            unset($_SESSION['basket']);
            $basketID = $session->get('basketID');
            $basket = false;
            if (!$basketID or ($basket = \Yii::$app->cache->get($basketID)) === false)
                $basketID = self::generateBasketID();

            // self::$sessID = $session->getId();
            self::getUserSessID();
            self::setBasketID($basketID);
            // $session->set('basketID', $basketID);

            // var_dump($basketID);
            // var_dump($basket);
            // exit;

            self::$instance = new Basket();
            self::$instance->users = new BasketUsers();
            if ($basket !== false) {
// var_dump($basket);
// exit;
                self::$instance->rest_id = $basket['rest_id'];

                /* check basket version */
                if (empty($basket['basketVersion']) or $basket['basketVersion'] != self::$instance->basketVersion) {
                    self::$instance->clear(true, false);
                    return self::$instance;
                }

                // $basket = unserialize($basket);
                self::$instance->isGroup = $basket['isGroup'];
                self::$instance->groupStatus = $basket['groupStatus'];
                if ($basket['owner'])
                    self::$instance->owner = $basket['owner'];
                self::$instance->coords = $basket['coords'];
                self::$instance->address = $basket['address'];
                self::$instance->delivery_cost = $basket['delivery_cost'];
                self::$instance->products  = $basket['products'];
                self::$instance->bonus_products = $basket['bonus_products'];
                self::$instance->delivery_free = $basket['free_delivery'];
                self::$instance->active_action = $basket['active_action'];
                self::$instance->bonus_active_action = $basket['bonus_active_action'];
                self::$instance->gift = $basket['gift'];
                self::$instance->promocode = !empty($basket['promocode']) ? $basket['promocode'] : null;
                if (isset($basket['delivery']))
                    self::$instance->delivery = $basket['delivery'];
                self::$instance->recalcBasket();

                /* is owner */
                // if (self::getSessID() == self::$instance->owner) {
                    // $coords = null;
                    // if (Yii::$app->request->cookies['current_coords'])
                        // $coords = Yii::$app->request->cookies['current_coords']->value;
                    // if (self::$instance->coords != $coords) {
                        // self::$instance->coords = $coords;
                        // if (self::$instance->isGroup)
                            // self::$instance->save(false);
                    // }
                // }
            }
            self::$instance->users->setStatusOwner(self::$instance->owner);
        }

        return self::$instance;
    }

    public function getGroupLink($absolute = true) {
        $urlData = ['/restaurants'];
        if ($this->rest_id)
            $urlData = ['restaurants/view', 'id' => $this->rest_id];

        $url = \yii\helpers\Url::to($urlData, $absolute);

        if (!$this->isGroup)
            return $url;

        $url .= '?groupId=' . urlencode(self::getBasketID());

        return $url;
    }

    public function getOwner() {
        return $this->owner;
    }

    public function isOwner($sessID = null) {
        // var_dump(self::getSessID());
        // var_dump($this->owner);
        // exit;
        if (!$sessID)
            $sessID = self::getSessID();

        $result = false;
        if ($sessID == $this->owner) {
            $result = true;
            $this->users->setStatusOwner($sessID);
        }

        return $result;
    }

    public function setIsGroup() {
        // echo self::getSessID() . '<br>';
        // echo $this->owner . '<br>';
        if (self::getSessID() == $this->owner) {
            $this->isGroup = true;
            $current = \frontend\widgets\WRsRegionAddressCurrent::get();
            $this->coords = $current['coords'];
            $address = null;
            if ($current['address'] and $current['full_address'])
                $address = [
                        'address' => $current['address'],
                        'full_address' => $current['full_address'],
                    ];
            $this->address = $address;
        }
        $this->save(false);

        return $this;
    }

    public function getAddress() {
        if ($this->isGroup)
            return \frontend\widgets\WRsRegionAddressCurrent::get(false, [
                        'coords' => $this->coords,
                        'address' => ($this->address ? $this->address['address'] : ''),
                        'full_address' => ($this->address ? $this->address['full_address'] : ''),
                    ]);
        return \frontend\widgets\WRsRegionAddressCurrent::get();
    }

    public function isGroup() {
        return $this->isGroup;
    }

    public function setGroupStatus($status) {
        if (!$this->isOwner())
            return $this;

        switch ($status) {
            case 0:
            case 1:
                break;
            default:
                $status = 0;
                break;
        }

        if ($this->getGroupStatus() == $status)
            return $this;

        $this->groupStatus = $status;
        $this->save();

        return $this;
    }

    public function getGroupStatus() {
        return $this->groupStatus;
    }

    public function getCoords() {
        return $this->coords;
    }

    public function getUsers() {
        return $this->users;
    }

    private function recalcBasket() {
        $this->count_products_item = 0;
        $this->sum_cost_products = 0;
        $this->sum_min_products = 0;
        $this->sum_cost_bonus = 0;
        $this->sum_cost_products_to_partner = 0;
        $this->total_sum = 0;
        if($this->delivery_free == false){
            //$this->setDeliveryPrice();
        }
        if (count($this->products) > 0) {
            $this->count_products = count($this->products);
            foreach ($this->products as $key => $product) {
                $this->count_products_item += $product->count;
                $this->sum_cost_products += $product->price;
                if (!$product->getProduct()->ignore_min_price)
                    $this->sum_min_products += $product->price;
                $this->sum_cost_products_to_partner += $product->price;
            }
        }
        if (count($this->bonus_products) > 0) {
            $this->count_products = count($this->bonus_products);
            foreach ($this->bonus_products as $key => $bproduct) {
                $this->count_products_item += $bproduct->count;
                $this->sum_cost_bonus += $bproduct->bonus_price;
                $this->sum_cost_products_to_partner += $bproduct->price;
            }
        }
        if($this->sum_cost_products != 0){
            $this->total_sum = $this->delivery_cost + $this->sum_cost_products;
        }
        else{
           $this->total_sum = $this->sum_cost_products;
        }

        $this->calculatedUsersProducts = [];
    }
    
    public function save($clearInstance = true) {
        $basket['basketVersion'] = $this->basketVersion;
        $basket['rest_id'] = $this->rest_id;
        $basket['isGroup'] = $this->isGroup;
        $basket['groupStatus'] = $this->groupStatus;
        $basket['coords'] = $this->coords;
        $basket['address'] = $this->address;
        $basket['owner'] = $this->owner;
        $basket['count_products'] = $this->count_products;
        $basket['count_products_item'] = $this->count_products_item;
        $basket['sum_cost_products'] = $this->sum_cost_products;
        $basket['sum_min_products'] = $this->sum_min_products;
        $basket['sum_cost_bonus'] = $this->sum_cost_bonus;
        $basket['sum_cost_products_to_partner'] = $this->sum_cost_products_to_partner;
        $basket['delivery_cost'] = $this->delivery_cost;
        $basket['total_sum'] = $this->total_sum;
        $basket['products'] = $this->products;
        $basket['bonus_products'] = $this->bonus_products;
        $basket['free_delivery'] = $this->delivery_free;
        $basket['active_action'] = $this->active_action;
        $basket['bonus_active_action'] = $this->bonus_active_action;
        $basket['gift'] = $this->gift;
        $basket['promocode'] = $this->promocode;
        if ($this->delivery)
            $basket['delivery'] = $this->delivery;

        $session = Yii::$app->session;
        if (!$session->isActive)
            $session->open();
        // $session->set('basket', serialize($basket));
        $basketID = self::getBasketID();
        $session->set('basketID', $basketID);
        \Yii::$app->cache->set($basketID, $basket, \Yii::$app->params['storeBasket']);
        /* clear instance for requests after save */
        if ($clearInstance)
            self::$instance = null;
    }

    
    public function setResaurant($rest_id) {
        if ($this->rest_id != $rest_id) {
            $this->rest_id = $rest_id;
            $deleteOld = true;
            // if ($this->isGroup)
                // $deleteOld = false;
            $this->clear(true);
        }
    }
    
    public function clearPromo(){
        foreach ($this->products as $key => $value) {
            $value->promo_id = '';
            $value->promo_price = 0;
        }
    }


    private function setPromo($bproduct_id, $promo_id, $promo_price){
        $this->products[$bproduct_id]->promo_id = $promo_id;
        $this->products[$bproduct_id]->promo_price = $promo_price;
        $this->save();
    }
    
    public function setPromo2($active_action, $bonus_active_action){
        
        $this->active_action = $active_action;
        $this->bonus_active_action = $bonus_active_action;
//        var_dump($this->bonus_active_action);
        $action_type = $active_action['promo_type'];
        $action_products = $active_action['promo_products'];
        $bonus_action_products = $bonus_active_action["promo_products"];
        $bonus_action_type = $bonus_active_action['promo_type'];
        // переписываем корзину под акцию
        foreach ($this->products as $key => $item) {
            $this->products[$key]->gift = '';
            $this->products[$key]->promo_bonus = 0;
            if (is_array($action_products)) {
                // echo '*'.$action_type.' == '.$action_products[$key]["extend_id"].'*';
                switch ($action_type) {
                    case 1:
                    case 2:
                        if (array_key_exists($key, $action_products)) {
                            $this->products[$key]->promo_id = $active_action['id'];
                            $this->products[$key]->promo_price = $action_products[$key]["price"];
                        }
                        else {
                            $this->products[$key]->promo_id = '';
                            $this->products[$key]->promo_price = 0;
                        };
                    break;
                    case 5:
                    case 6:
                        if ($item->extend_id == $action_products[$key]["extend_id"]) {
                            $this->products[$key]->promo_id = $active_action['id'];
                            $this->products[$key]->promo_price = 0;
                            $this->products[$key]->gift = $action_products[$key]["gift"];
                        };
                    break;
                    case 7:
                    case 8:
                        $this->products[$key]->promo_id = '';
                        $this->products[$key]->promo_price = 0;
                        $this->products[$key]->gift = '';
                    break; //зарезервировано

                    default:
                        $this->products[$key]->promo_id = '';
                        $this->products[$key]->promo_price = 0;
                        $this->products[$key]->gift = '';
                    break;
                }
                if (array_key_exists($key, $action_products)) {
                    $this->products[$key]->promo_id = $active_action['id'];
                    $this->products[$key]->promo_price = $action_products[$key]["price"];
                }
                else {
                    $this->products[$key]->promo_id = '';
                    $this->products[$key]->promo_price = 0;
                    $this->products[$key]->gift = '';
                    
                }
            }
            if (is_array($bonus_action_products)) {
                // echo '*'.$bonus_action_type.' == '.$bonus_action_products[$key]["bonus"].'*';
                switch ($bonus_action_type) {
                    case 3:
                    case 4:
                        if (array_key_exists($key, $bonus_action_products)) {
                            $this->products[$key]->bonus_promo_id = $bonus_active_action['id'];
                            $this->products[$key]->promo_bonus = $bonus_action_products[$key]["bonus"];
                        }
                        else
                            $this->products[$key]->promo_bonus = 0;
                    break;
                    default: break;
                }
            } 
        }
//        echo '<pre>';
//        var_dump($bonus_action_products);
//        var_dump($this->bonus_active_action);
//        echo '</pre>';
        $this->save();
    }
    
    public function getActiveAction(){
        return $this->active_action;
    }

    public function setPromocode($code) {
        $this->promocode = $code;
        return $this;
    }

    public function clearPromocode() {
        $this->promocode = null;
        return $this;
    }

    public function getPromocode() {
        return $this->promocode;
    }

    public function setGift(Products $gift){
        $this->gift = $gift;
    }
    
    public function clearGift(){
        $this->gift = '';
    }
    
    public function getGift(){
        return $this->gift;
    }
    
    public function getBonusActiveAction(){
        return $this->bonus_active_action;
    }


    protected function setDeliveryPrice(){
        if(!$this->delivery_flag){
            $delivery = \common\models\CityDelivery::getDeliveryInfo();
            $this->min_sum = $delivery[$this->getRestId()]['min_sum'];
            $this->delivery_cost = $delivery[$this->getRestId()]['delivery_price'];
        }
        else{
            $this->min_sum = $this->delivery->min_sum;
            $this->delivery_cost = $this->delivery->price;
        }
    }

    public function setDeliveryPriceByChangeRegion($delivery, $free_summ , $current_summ, $delivery_summ){
        if($current_summ > $free_summ){
            $this->delivery_cost = 0;
            $this->delivery_free = true;
        }
        else{
            $this->delivery_cost = $delivery_summ;
            $this->delivery_free = false;
        }
        $this->delivery_flag = true;
        $this->delivery = $delivery;
        $this->recalcBasket();
        $this->save();
    }
    
    public function setDeliveryFree($free_summ , $current_summ, $delivery_summ, $delivery_summ_rules = []){
        if ($current_summ >= $free_summ && $free_summ > 0) {
            $this->delivery_cost = 0;
            $this->delivery_free = true;
        }
        else {
            if ($delivery_summ_rules) {
                $first = true;
                foreach ($delivery_summ_rules as $min => $summ) {
                    if ($current_summ < $min) {
                        if ($first) {
                            $this->delivery_cost = $delivery_summ;
                            $this->delivery_free = false;
                        }
                        break;
                    }
                    $first = false;
                    if ($current_summ >= $min) {
                        if ($summ == 0) {
                            $this->delivery_cost = 0;
                            $this->delivery_free = true;
                            break;
                        }
                        $this->delivery_cost = $summ;
                        $this->delivery_free = false;
                        continue;
                    }
                }
            }
            else {
                $this->delivery_cost = $delivery_summ;
                $this->delivery_free = false;
            }
        }
        $this->recalcBasket();
        $this->save();
    }
    
    public function getRestId() {
        return $this->rest_id;
    }
    
    public function addProduct($product_id, Products $product, $bonus_flag, $dish_consist = false, $property_id, $product_comment = false, $count = null) {
        if(!isset($product_comment) || $product_comment == false){ $product_comment = '';}
        $this->setResaurant($product->rest_id);
        if (is_array($dish_consist)) {
            ksort($dish_consist);
        }
        else
            $dish_consist = false;
        $flag_new = true;
        if ($bonus_flag == 1) {

            if(!$this->isOwner()){
                Yii::$app->session->setFlash('alert', \Yii::t('restaurants', 'Only purchaser can add dish for points in group order.'));
                return false;
            }

            if(count($this->bonus_products)){
                Yii::$app->session->setFlash('alert', \Yii::t('restaurants', 'You can add only one dish for points in your order.'));
                return false;
            }

            $balance_info = Yii::$app->user->identity->getBonusBalance();
            $foody_balance = $balance_info["foody"]["bonus_balance"];
            $onecard_balance = $balance_info["onecard"]["bonus_balance"];
            $total_bonus_balance = $foody_balance + $onecard_balance;
            $prp = RestDishesGrade::findOne($property_id);
                
            if (!empty($this->bonus_products)) {
                if($total_bonus_balance < ($prp->price_bonus + $this->sum_cost_bonus)){
                    Yii::$app->session->setFlash('error', \Yii::t('restaurants', 'Your bonuses are not enough'));
                    return false;
                }
                foreach ($this->bonus_products as $key => $bproduct) {
                    if ($bproduct->getProductId() == $product_id && $bproduct->getConsist() == $dish_consist && !empty($dish_consist) && $property_id == $bproduct->extend_id) {
                        $bproduct->plus(); // добавляем к существующему продукту с добавками количество +1
                        $flag_new = false; // флаг что продукт не новый
                        break;
                    }
                    elseif ($bproduct->getProductId() == $product_id  && !$bproduct->getConsist() && empty($dish_consist) && $property_id == $bproduct->extend_id) {
                        $bproduct->plus(); // добавляем к существующему продукту без добавок количество +1
                        $flag_new = false; // флаг что продукт не новый
                        break;
                    }
                }
            }
            if ($flag_new == true) {
                if($total_bonus_balance < $prp->price_bonus){
                    Yii::$app->session->setFlash('error', \Yii::t('restaurants', 'Your bonuses are not enough'));
                    return false;
                }
                
                $new_product = new BasketProduct($product, 1 , $property_id, $dish_consist, $product_comment);
                $property = $prp;
                $new_product->extend_name = $property->name;
                $new_product->SetPrice($property->price);
                $new_product->SetBonus($property->price_bonus);
                array_push($this->bonus_products, $new_product);
            }
        } else {
            if (!empty($this->products)) {
                foreach ($this->products as $key => $bproduct) {
                    if ($bproduct->getProductId() == $product_id && $bproduct->getConsist() == $dish_consist && !empty($dish_consist) && $property_id == $bproduct->extend_id) {
                        $bproduct->plus($count); // добавляем к существующему продукту с добавками количество +1 или кол-во
                        $flag_new = false; // флаг что продукт не новый
                        break;
                    }
                    elseif ($bproduct->getProductId() == $product_id && !$bproduct->getConsist() && !$dish_consist && $property_id == $bproduct->extend_id) {
                        $bproduct->plus($count); // добавляем к существующему продукту без добавок количество +1 или кол-во
                        $flag_new = false; // флаг что продукт не новый
                        break;
                    }
                }
            }
            if ($flag_new == true) {
                $new_product = new BasketProduct($product, 0 , $property_id,  $dish_consist, $product_comment);
                $property = RestDishesGrade::findOne(['id' => $property_id]);
                $new_product->extend_name = $property->name;
                $new_product->SetPrice($property->price);
                if ($count)
                    $new_product->instance($count);
                array_push($this->products, $new_product);
            }
        }
        $this->recalcBasket();
        $this->save();
    }

    public function editProduct($basket_product_id, $bonus_flag, $property_id, $consist, $product_comment = false) {
        if (!isset($product_comment) || $product_comment == false)
            $product_comment = '';
        if ($bonus_flag == 1) {
            $balance_info = Yii::$app->user->identity->getBonusBalance();
            $foody_balance = $balance_info["foody"]["bonus_balance"];
            $onecard_balance = $balance_info["onecard"]["bonus_balance"];
            $total_bonus_balance = $foody_balance + $onecard_balance;
            $valid_bonus = $this->bonus_products;
            foreach ($valid_bonus as $key => $value) {
                if($key == $basket_product_id){
                    $basket_bonus += $value->single_bonus_price * ($value->count + 1);
                }
                $basket_bonus += $value->single_bonus_price * $value->count;
            }
            if($total_bonus_balance < $basket_bonus){
                Yii::$app->session->setFlash('error', \Yii::t('restaurants', 'Your bonuses are not enough'));
                return false;
            }
            $property = RestDishesGrade::findOne(['id' => $property_id]);
            $this->bonus_products[$basket_product_id]->extend_name = $property->name;
            $this->bonus_products[$basket_product_id]->SetPrice($property->price);
            $this->bonus_products[$basket_product_id]->SetBonus($property->price_bonus);
            $this->bonus_products[$basket_product_id]->setExtendId($property_id);
            $this->bonus_products[$basket_product_id]->setConsist($consist);
        }
        else {
            // if ($this->isGroup()) {
                $product = $this->products[$basket_product_id]->getProduct();
                $productCount = $this->products[$basket_product_id]->getOwnerCount();
                if ($this->products[$basket_product_id]->getOtherOwnersCount())
                    $this->products[$basket_product_id]->instance(0);
                else {
                    $this->products[$basket_product_id]->deleteOwner();
                    unset($this->products[$basket_product_id]);
                }
                return $this->addProduct($product->id, $product, $bonus_flag, $consist, $property_id, $product_comment, $productCount);
            // }
            // else {
                // $property = RestDishesGrade::findOne(['id' => $property_id]);
                // $this->products[$basket_product_id]->extend_name = $property->name;
                // $this->products[$basket_product_id]->SetPrice($property->price);
                // $this->products[$basket_product_id]->setExtendId($property_id);
                // $this->products[$basket_product_id]->setConsist($consist);
            // }
        }
        $this->recalcBasket();
        $this->save();
    }
    
    public function plusProduct($basket_product_id, $bonus_flag) {
        if ($bonus_flag == 1) {
            if (count($this->bonus_products)) {
                Yii::$app->session->setFlash('alert', \Yii::t('restaurants', 'You can add only one dish for points in your order.'));
                return false;
            }
            $balance_info = Yii::$app->user->identity->getBonusBalance();
            $foody_balance = $balance_info["foody"]["bonus_balance"];
            $onecard_balance = $balance_info["onecard"]["bonus_balance"];
            $total_bonus_balance = $foody_balance + $onecard_balance;
            $valid_bonus = $this->bonus_products;
            foreach ($valid_bonus as $key => $value) {
                if ($key == $basket_product_id)
                    $basket_bonus += $value->single_bonus_price * ($value->count + 1);
                $basket_bonus += $value->single_bonus_price * $value->count;
            }
            if ($total_bonus_balance < $basket_bonus) {
                Yii::$app->session->setFlash('error', \Yii::t('restaurants', 'Your bonuses are not enough'));
                return false;
            }
            $this->bonus_products[$basket_product_id]->plus();
        }
        else
            $this->products[$basket_product_id]->plus();
        $this->recalcBasket();
        $this->save();
    }
    
    public function instanceProduct($basket_product_id, $bonus_flag, $count){
        if ($bonus_flag == 1) {
            if(count($this->bonus_products)){
                Yii::$app->session->setFlash('alert', \Yii::t('restaurants', 'You can add only one dish for points in your order.'));
                return false;
            }
            $balance_info = Yii::$app->user->identity->getBonusBalance();
            $foody_balance = $balance_info["foody"]["bonus_balance"];
            $onecard_balance = $balance_info["onecard"]["bonus_balance"];
            $total_bonus_balance = $foody_balance + $onecard_balance;
            $new_bonus = $this->getNewBonusBalance($basket_product_id, $count);

            if($total_bonus_balance < $new_bonus){
               Yii::$app->session->setFlash('error', \Yii::t('restaurants', 'Your bonuses are not enough'));
               return false;
            }
            $this->bonus_products[$basket_product_id]->instance($count);
        }
        else
            $this->products[$basket_product_id]->instance($count);
        $this->recalcBasket();
        $this->save();
    }
    
    public function deleteProduct($basket_product_id, $bonus_flag) {
        if ($bonus_flag == 1) {
            if (!empty($this->bonus_products[$basket_product_id])) {
                if ($this->bonus_products[$basket_product_id]->getOtherOwnersCount())
                    $this->bonus_products[$basket_product_id]->instance(0);
                else {
                    $this->bonus_products[$basket_product_id]->deleteOwner();
                    unset($this->bonus_products[$basket_product_id]);
                }
            }
        }
        else {
            if (!empty($this->products[$basket_product_id])) {
                if ($this->products[$basket_product_id]->getOtherOwnersCount())
                    $this->products[$basket_product_id]->instance(0);
                else {
                    $this->products[$basket_product_id]->deleteOwner();
                    unset($this->products[$basket_product_id]);
                }
            }
        }
        if (empty($this->products) && empty($this->bonus_products))
            $this->setEmpty();
        $this->recalcBasket();
        $this->save();
    }

    public function minusProduct($basket_product_id, $bonus_flag) {
        if ($bonus_flag == 1) {
            if ($this->bonus_products[$basket_product_id]->count <= 1)
                return $this->deleteProduct($basket_product_id, $bonus_flag);
            else
                $this->bonus_products[$basket_product_id]->minus();
        }
        else {
            if ($this->products[$basket_product_id]->count <= 1)
                return $this->deleteProduct($basket_product_id, $bonus_flag);
            else
                $this->products[$basket_product_id]->minus();
        }
        if (empty($this->products) && empty($this->bonus_products))
            $this->setEmpty();
        $this->recalcBasket();
        $this->save();
    }
    
    private function getNewBonusBalance($basket_product_id, $count) { 
        $tmp = $this->bonus_products;
        foreach ($tmp as $key => $bproduct) {
            if ($key == $basket_product_id)
                $tmp_sum_cost_bonus += $count * $bproduct->single_bonus_price;
            $tmp_sum_cost_bonus += $bproduct->count * $bproduct->single_bonus_price;
        }

        return $tmp_sum_cost_bonus;
    }

    public function getCountProducts() {
        return $this->count_products;
    }
    
    public function getCountProductsItems() {
        return $this->count_products_item;
    }
    
    public function getSumProducts() {
        return $this->sum_cost_products;
    }

    public function getSumMinProducts() {
        return $this->sum_min_products;
    }

    public function getSumProductsForPartner() {
        return $this->sum_cost_products_to_partner;
    }
    
    public function getSumBonus() {
         return intval($this->sum_cost_bonus);
    }
    
    public function getTotalSumProducts() {
        return $this->total_sum;
    }
    
    public function getDeliverySum() {
        return $this->delivery_cost;
    }
    
    public function getProducts() {
        return $this->products;
    }
    
    public function getProduct($id_product) {
        foreach ($this->products as $key => $value) {
            if($id_product == $value->getProductId()){
               return $value; 
            }
        }
    }
    
    public function getBasketProductID($id_product, $id_property = false) {
        foreach ($this->products as $key => $value) {
            if ($id_property != false) {
               if ($id_product == $value->getProductId() && $id_property == $value->extend_id)
                    return $key;
            }
            else {
                if ($id_product == $value->getProductId())
                    return $key;
            }
        }
    }
    
    public function getMinSum($flag = false){
        if (!$flag) {
            $basketCoords = -1;
            if ($this->isGroup)
                $basketCoords = $this->coords;
            $delivery = \common\models\CityDelivery::getDeliveryInfo($basketCoords);
            // $model = Restaurants::findOne($this->rest_id);
            $this->min_sum = $delivery[$this->rest_id]['min_sum'];
        }
        else
            $this->min_sum = $this->delivery->min_sum;

        return $this->min_sum;
    }
    
    public function getBonusProducts() {
        return $this->bonus_products;
    }

    private $calculatedUsersProducts = [];

    public function calculateUsersProducts() {
        $users = $this->users->getAll();

        $usersProducts = [];
        foreach ($this->products as $basket_num => $product) {
            $priceAll = $product->price;
            if (($productCount = $product->count) <= 0)
                continue;
            foreach ($users as $sessID => $user) {
                $userCount = $product->getOwnerCount($sessID);
                if (!isset($usersProducts[$basket_num]))
                    $usersProducts[$basket_num] = [];
                $usersProducts[$basket_num][$sessID] = [
                                'count' => $userCount,
                                'price' => (($priceAll / $productCount) * $userCount),
                            ];
            }
        }

        $usersBonusProducts = [];
        foreach ($this->bonus_products as $basket_num => $product) {
            $priceAll = $product->bonus_price;
            if (($productCount = $product->count) <= 0)
                continue;
            foreach ($users as $sessID => $user) {
                $userCount = $product->getOwnerCount($sessID);
                if (!isset($usersBonusProducts[$basket_num]))
                    $usersBonusProducts[$basket_num] = [];
                $usersBonusProducts[$basket_num][$sessID] = [
                                'count' => $userCount,
                                'bonus_price' => (($priceAll / $productCount) * $userCount),
                            ];
            }
        }

        return $this->calculatedUsersProducts = [
                        'products' => $usersProducts,
                        'bonus_products' => $usersBonusProducts,
                    ];
    }

    public function getProductUsers($basket_num, $ignoreEmpty = true) {
        if (!$this->calculatedUsersProducts)
            $this->calculateUsersProducts();

        $results = [];
        if (!empty($this->calculatedUsersProducts['products'][$basket_num]))
            $results = $this->calculatedUsersProducts['products'][$basket_num];

        if ($results and $ignoreEmpty) {
            foreach ($results as $sessID => $result) {
                if (!$result['count']) {
                    unset($results[$sessID]);
                    if (empty($results)) {
                        $results = [];
                        break;
                    }
                }
            }
        }

        return $results;
    }

    public function isProductUser($basket_num, $sessID = null) {
        if (!$sessID)
            $sessID = self::getSessID();

        $users = $this->getProductUsers($basket_num, true);
        if (!empty($users[$sessID]))
            return true;
        return false;
    }

    public function getBonusProductUsers($basket_num, $ignoreEmpty = true) {
        if (!$this->calculatedUsersProducts)
            $this->calculateUsersProducts();

        $results = [];
        if (!empty($this->calculatedUsersProducts['bonus_products'][$basket_num]))
            $results = $this->calculatedUsersProducts['bonus_products'][$basket_num];

        if ($results and $ignoreEmpty) {
            foreach ($results as $sessID => $result) {
                if (!$result['count']) {
                    unset($results[$sessID]);
                    if (empty($results)) {
                        $results = [];
                        break;
                    }
                }
            }
        }

        return $results;
    }

    public function isBonusProductUser($basket_num, $sessID = null) {
        if (!$sessID)
            $sessID = self::getSessID();

        $users = $this->getBonusProductUsers($basket_num, true);
        if (!empty($users[$sessID]))
            return true;
        return false;
    }

    public function calculateUsersTotal() {
        if (!$this->calculatedUsersProducts)
            $this->calculateUsersProducts();

        $calculated = [];
        $users = $this->users->getAll();
        foreach ($users as $sessID => $user) {
            $userPrice = 0;
            $userBonusPrice = 0;
            foreach ($this->calculatedUsersProducts['products'] as $data) {
                if (!empty($data[$sessID]))
                    $userPrice += $data[$sessID]['price'];
            }
            foreach ($this->calculatedUsersProducts['bonus_products'] as $data) {
                if (!empty($data[$sessID]))
                    $userBonusPrice += $data[$sessID]['bonus_price'];
            }

            $calculated[$sessID] = [
                                    'price' => $userPrice,
                                    'bonus_price' => $userBonusPrice,
                                ];
        }

        return $calculated;
    }

    public function leaveGroup($sessID = null) {
        $users = $this->users;

        if (!$sessID)
            $sessID = self::getSessID();

        if ($this->isOwner())
            return $this->clear(true, true);

        /* if not owner we must remove all products of this user */
        if ($this->products) {
            foreach ($this->products as $basket_num => $data) {
                if ($this->isProductUser($basket_num, $sessID))
                    $this->deleteProduct($basket_num, 0);
            }
        }
        if ($this->bonus_products) {
            foreach ($this->bonus_products as $basket_num => $data) {
                if ($this->isBonusProductUser($basket_num, $sessID))
                    $this->deleteProduct($basket_num, 1);
            }
        }

        $users->deleteUser($sessID);

        return $this->clear(false, false);
    }

    public function clear($deleteOld = true, $clearInstance = true){
        if ($deleteOld and $this->isOwner()) {
            $old = self::getBasketID();
            \Yii::$app->cache->delete($old);
            if ($this->users)
                $this->users->delete();
        }
        self::resetBasketID();
        $this->isGroup = false;
        $this->groupStatus = 0;
        $this->owner = null;
        $this->coords = null;
        $this->address = null;
        $this->setEmpty()->save($clearInstance);

        return $this;
    }

    private function setEmpty() {
        $this->count_products = 0;
        $this->count_products_item = 0;
        $this->sum_cost_products = 0;
        $this->sum_min_products = 0;
        $this->sum_cost_bonus = 0;
        $this->sum_cost_products_to_partner = 0;
        $this->delivery_cost = 0;
        $this->total_sum = 0;
        $this->gift = '';
        $this->promocode = null;
        $this->products = [];
        $this->bonus_products = [];

        return $this;
    }
}
