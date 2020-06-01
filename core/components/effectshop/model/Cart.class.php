<?php
namespace Shop;

/**
 * Отвечает за корзину на сайте, расчёт цен, скидок в заказе
 */
class Cart
{

	public function __construct($order = false)
	{
		global $modx;
		$this->modx = &$modx;

		if (!$order) {
			if (empty($_SESSION['shop_cart']) || empty($_SESSION['shop_cart']['items'])) {
				$_SESSION['shop_cart'] = ['items' => []];
			}
			$this->cart = &$_SESSION['shop_cart'];
		} else {
			$this->cart = &$order;
		}
	}


	/**
	 * Ajax запросы
	 */
	public function request($action)
	{
		$output = [];
		$params = $_POST['params'] ?? [];

		switch ($action) {
			case 'add':
				$output['product'] = $this->add((int)$_POST['id'], (int)$_POST['qty'], $params);
				break;
			case 'remove':
				$output['product'] = $this->remove((int)$_POST['index']);
				break;
			case 'qty':
				$output['product'] = $this->qty((int)$_POST['index'], (int)$_POST['qty']);
				break;
			case 'change':
				$output['product'] = $this->change((int)$_POST['index'], $params);
				break;
			default:	
		}

		$output['cart'] = $this->processCart();
		$output[0] = 1;
		return $output;
	}


	/**
	 * 
	 */
	private function add(int $id, int $qty = 1, array $params = [])
	{
		$product = Catalog::getOne($id);
		if (empty($product)) return false;

		$product['price'] = $this->cleanPrice($product['price']);
		$product['qty'] = $this->cleanCount($qty) ?: 1;
		$product['url'] = $this->modx->makeUrl($product['id'], '', '', 'full' );
		
		if (!empty($params['opts'])) {
			foreach ($params['opts'] as $name => $opt) {
				$product['options'][$name] = $opt;
			}
		}
		if (isset($params['adds']) && !empty($product['adds'])) {
			$product = $this->addAdds($product, $params['adds']);
		}

		$intersect = $this->checkIntersect($product);
		if ($intersect === false) {
			array_push($this->cart['items'], $product);
		} else {
			$this->cart['items'][$intersect]['qty'] += $product['qty'];
		}

		return $product;
	}


	/**
	 * 
	 */
	private function addAdds(array $product, $adds)
	{
		$adds = gettype($adds) == 'array' ? $adds : [];
		$product['adds_values'] = [];
		foreach ($adds as $add) {
			$product['adds_values'][] = (int)$add;
		}
		array_unique($product['adds_values']);
		foreach ($product['adds'] as $key => $add) {
			$product['adds'][$key]['qty'] = 0;
			if (in_array($add['id'], $product['adds_values'])) {
				$product['adds'][$key]['qty'] = 1;
			}
		}

		return $product;
	}


	/**
	 * 
	 */
	private function qty(int $index, $qty)
	{
		$product = [];
		if ($this->cart['items'][$index]) {
			$product = $this->cart['items'][$index];
			$this->cart['items'][$index]['qty'] = $this->cleanCount($qty);
		}
		return $product;
	}


	/**
	 * 
	 */
	private function change(int $index, array $params)
	{
		$product = [];

		if ($this->cart['items'][$index]) {
			$product = $this->cart['items'][$index];

			foreach ($params as $name => $val) {
				if ($name == 'qty') {
					$this->cart['items'][$index]['qty'] = $this->cleanCount($val);
				}
				if ($name == 'adds' && !empty($product['adds'])) {
					$product = $this->addAdds($product, $val);
					$this->cart['items'][$index] = $product;
				}
			}
		}

		return $product;
	}
	

	/**
	 * 
	 */
	private function remove(int $index)
	{
		$product = [];
		if ($this->cart['items'][$index]) {
			$product = $this->cart['items'][$index];
			array_splice($this->cart['items'], $index, 1);
		}
		return $product;
	}


	/**
	 * 
	 */
	public function processCart($order = false)
	{
		$order = $order ?: $this->cart;

		$order['price'] = 0;
		$order['qty'] = 0;
		
		foreach($order['items'] as $k => &$item) {
			$item['qty'] = (int)$item['qty'];
			$item['initial_price'] = (float)$item['price'];
			$item['price'] = $item['initial_price'];

			if (!empty($item['adds'])) {
				foreach ($item['adds'] as $add) {
					$addPrice = (float)$add['price'] * ($add['qty'] ?? 0);
					$item['price'] += $addPrice;
				}
			}

			$order['qty'] += $item['qty'];
			$item['total_price'] = round(($item['price'] * $item['qty']), 2);
			$order['price'] += $item['total_price'];
		}
		unset($item);
		
		$order['price'] = round($order['price'], 2);
		$order['discount'] = $order['discount'] ?? 0;
		$order['delivery_price'] = $order['delivery_price'] ?? 0;
		$discount = $order['price'] * (((float)$order['discount']) / 100);
		$order['total_price'] = $order['price'] + ((float)$order['delivery_price']) - $discount;

		return $order;
	}


	/**
	 * Обрезка картинок в корзине
	 */
	public function cropImages()
	{
		$cfg = Params::cfg();
		foreach ($this->cart['items'] as $k => &$item ) {
			if (!empty($item['image']) && empty($item['thumb'])) {
				$path = stripos($item['image'], 'assets/mgr') === false ? "/assets/mgr/{$item['image']}" : $item['image'];
				$thumb = $this->modx->runSnippet('phpthumbon', [
					'input' => $path,
					'options' => $cfg['thumb'] ?? 'w=70&h=70',
				]);
				$item['thumb'] = $thumb;
			}
		}
	}

	
	/**
	 * Проверяем, есть ли уже товар в корзине
	 */
	private function checkIntersect($product)
	{
		$output = false;
		for( $i=0; $i < count($this->cart['items']); $i++ ){
			if (
				$this->cart['items'][$i]['id'] == $product['id']
				&& $this->cart['items'][$i]['price'] == $product['price']
				&& serialize($this->cart['items'][$i]['opts']) == serialize($product['opts'])
				&& serialize($this->cart['items'][$i]['adds_values']) == serialize($product['adds_values'])
			) {
				$output = $i;
				break;
			}
		}
		return $output;
	}
	

	/**
	 * Проверяет введенное число кол-ва товаров и приводит к нормальному виду
	 */
	private function cleanCount($count)
	{
		$output = str_replace(array(',',' '),array('.',''),$count);
		if(!is_numeric($output) || empty($output)) return 1;
		return abs((int)$output);
	}


	/**
	 * 
	 */
	private function cleanPrice($price = 0)
	{
		$price = str_replace([',', ' '], ['.', ''], (string)$price);
		return round((float)$price, 2);
	}


}