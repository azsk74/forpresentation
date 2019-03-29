<?
namespace Azsk\Adtk;

use Bitrix\Main\Loader,
	Bitrix\Sale,
	Bitrix\Sale\Order,
	Bitrix\Sale\Delivery;

class Algorithm
{
	var $Product;
	var $city;
	var $quantity;
	
	/**
	 * Входные параметры: ID продуктов, количество('ID продукта'=>'VALUE'), город на русском, код местоположения от объекта модуля \BXmaker\GeoIP\Manager
	 * Суть: Формирует данные для объекта
	 * Возвращает: отсутствует
	*/
	function __construct($arProduct, $quantityProduct, $arCity, $arLocation) {
		$this->product = $arProduct;
		
		$this->quantity = $quantityProduct;
		
		$this->city = $arCity;
		
		$this->location = $arLocation;
	}

	/**
	 * Входные параметры: От объекта класса
	 * Суть: Просчитываем доставку с тремя выводами: самовывоз, доставка со сроком, доставка без срока
	 * Возвращает: Массив с координатами, массив с доставкой если она была просчитана
	*/
	public function calculationDeliveryOneProduct() {
		$dataCosting = array();
		$nearest_warehouse = array();
		$siteId = \Bitrix\Main\Context::getCurrent()->getSite();
		
		#Запрос всех данных о продукте
		$dataCosting['productList'] = $this->productDataRequest();
		foreach($dataCosting['productList'] as $item) {
			$dataCosting['productList'] = $item;
		}
		if(!empty($dataCosting['productList'])) {
			#Запрос координат местоположения
			$dataCosting['coordinates'] = $this->locationCoordinates();

			if(!empty($dataCosting['coordinates'])) {
				#Перебор массива складов
				foreach($dataCosting['productList'] as $key => $item) {
					#Товары есть в наличии?
					if($item['AMOUNT']>0) {
						if($item['STORE_GPS_N'] >= $dataCosting['coordinates']['gps']['bounds']['southwest']['lat'] 
						 & $item['STORE_GPS_N'] <= $dataCosting['coordinates']['gps']['bounds']['northeast']['lat']
						 & $item['STORE_GPS_S'] >= $dataCosting['coordinates']['gps']['bounds']['southwest']['lng']
						 & $item['STORE_GPS_S'] <= $dataCosting['coordinates']['gps']['bounds']['northeast']['lng']) {
						 	#Это самовывоз! доступно в городе
						 	unset($dataCosting);
						 	$dataCosting['EXPORTATION_SELF']= 'Y';
						 	$dataCosting['STORE'] = $item;
						 	return $dataCosting;
						 } else {
							#Формула Винценти погрешность до 1км все зависит от геолокации
							if(!empty($nearest_warehouse)) {
								if(self::calculateTheDistance(
									$nearest_warehouse['STORE_GPS_N'],
									$nearest_warehouse['STORE_GPS_S'],
									$dataCosting['coordinates']['gps']['lat'],
									$dataCosting['coordinates']['gps']['lng']
								) > self::calculateTheDistance(
									$item['STORE_GPS_N'],
									$item['STORE_GPS_S'],
									$dataCosting['coordinates']['gps']['lat'],
									$dataCosting['coordinates']['gps']['lng']
								)) {
									$nearest_warehouse = $item;
								}
							 } else {
								 $nearest_warehouse = $item;
							 }					 
						 }
					}
				}

				unset($dataCosting['productList']);
				if(empty($nearest_warehouse)) {
					#Расчитываем доставку по умолчанию
					#$arField = array('SITE_ID'=>$siteId,'LOCATION'=>$this->location,'PERSONAL_TYPE'=>'1');
					#$arField['PRODUCT'][] = array('PRODUCT_ID'=>$this->product,'QUANTITY'=>$this->quantity[$this->product]);
					
					$dataCosting['DEFAULT_DELIVERY'] = 'Y';
					#$dataCosting['CALCULATE_DELIVERY'] = self::calculateDelivery($arField, false);
				} else {
					#Расчитываем доставку с ближайшего склада
					$arField = array('SITE_ID'=>$siteId,'LOCATION'=>$this->location,'PERSONAL_TYPE'=>'1');
					$arField['PRODUCT'][] = array('PRODUCT_ID'=>$this->product,'QUANTITY'=>$this->quantity[$this->product]);
					
					$dataCosting['NEAREST_WAREHOUSE'] = 'Y';
					$dataCosting['CALCULATE_DELIVERY'] = self::calculateDelivery($arField, $nearest_warehouse['STORE_NAME']);
				}
				
			} else {
				unset($dataCosting);
				$dataCosting['ERROR'] = 'Нет координат. проверьте соединение с yandex.maps';
				$dataCosting['ERROR_TYPE'] = 'GPS_ERROR';
				return $dataCosting;
			}
		} else {
			unset($dataCosting);
			$dataCosting['ERROR'] = 'Нет товаров, проверьте подключаемый компонент и его параметры';
			$dataCosting['ERROR_TYPE'] = 'PRODUCT_ERROR';
			return $dataCosting;
		}
		
		if(!empty($dataCosting['CALCULATE_DELIVERY']['ERROR'])) {
			$error = $dataCosting['CALCULATE_DELIVERY']['ERROR'];
			$error_type = $dataCosting['CALCULATE_DELIVERY']['ERROR_TYPE'];
			unset($dataCosting);
			$dataCosting['ERROR'] = $error;
			$dataCosting['ERROR_TYPE'] = $error_type;
		}
		
		return $dataCosting;
	}

	/**
	 * Входные параметры: От объекта класса
	 * Суть: Расчет доставки когда больше одного товара, доставка будет считатся со склада где больше всего товаров
	 * Возвращает: Массив с координатами, массив с доставкой если она была просчитана
	*/
	public function calculationDeliveryMoreProduct() {
		$dataCosting = array();
		
		$siteId = \Bitrix\Main\Context::getCurrent()->getSite();
		
		#Запрашиваем информацию по складам по всем товарам
		$dataCosting['productList'] = $this->productDataRequest();
		if(!empty($dataCosting['productList'])) {
			#Запрос координат местоположения
			$dataCosting['coordinates'] = $this->locationCoordinates();
			if(!empty($dataCosting['coordinates'])) {
				#Проверяем наличие на складах
				$AmountStoreCheckResult = $this->checkAmountStore($dataCosting['productList']);

				#Удаляем массив складов из результирующего массива
				unset($dataCosting['productList']);

				#Проверяем что все товары есть в наличии
				$dataCosting['HAVE_ALL_AMOUNT'] = $this->checkAllAmountProduct($AmountStoreCheckResult['QUANTITY_LIMIT']);

				#Пробуем считать доставки от разных складов
				if(!empty($AmountStoreCheckResult['STORE_RETURN'])) {
					#Формируем параметры для расчетов
					$arField = array('SITE_ID'=>$siteId,'LOCATION'=>$this->location,'PERSONAL_TYPE'=>'1');
					foreach($this->product as $item) {
						$arField['PRODUCT'][] = array('PRODUCT_ID'=>$item,'QUANTITY'=>$this->quantity[$item]);
					}

					#Перебираем склады
					foreach($AmountStoreCheckResult['STORE_RETURN'] as $store) {
						if($store['AMOUNT'] > 0) {
							#Считаем доставку от данного склада
							$dataCosting['CALCULATE_DELIVERY'] = self::calculateDelivery($arField, $store['STORE_NAME']);
							
							#Это не первый расчет?
							if(!empty($calculate_prev)) {
								#Перебираем массив подсчетов
								foreach($dataCosting['CALCULATE_DELIVERY'] as $key => $calculate) {
									#Стоимость больше предыдущей?
									if($calculate['PRICE'] > $calculate_prev[$key]['PRICE']) {
										$calculate_prev = $dataCosting['CALCULATE_DELIVERY'];	
									}
								}
							} else {
								$calculate_prev = $dataCosting['CALCULATE_DELIVERY'];
							}
						}
					}
					$dataCosting['SUITABLE_DELIVERY'] = 'Y';
					$dataCosting['CALCULATE_DELIVERY'] = $calculate_prev;
				} else {
					#Считаем доставку по умолчанию
					$arField = array('SITE_ID'=>$siteId,'LOCATION'=>$this->location,'PERSONAL_TYPE'=>'1');
					foreach($this->product as $item) {
						$arField['PRODUCT'][] = array('PRODUCT_ID'=>$item,'QUANTITY'=>$this->quantity[$item]);
					}
					$dataCosting['DEFAULT_DELIVERY'] = 'Y';
					$dataCosting['CALCULATE_DELIVERY'] = self::calculateDelivery($arField, false);
				}
				
			} else {
				unset($dataCosting);
				$dataCosting['ERROR'] = 'Нет координат. проверьте соединение с yandex.maps';
				$dataCosting['ERROR_TYPE'] = 'GPS_ERROR';
				return $dataCosting;
			}
		} else {
			unset($dataCosting);
			$dataCosting['ERROR'] = 'Нет товаров, проверьте подключаемый компонент и его параметры';
			$dataCosting['ERROR_TYPE'] = 'PRODUCT_ERROR';
			return $dataCosting;
		}
		
		if(!empty($dataCosting['CALCULATE_DELIVERY']['ERROR'])) {
			$error = $dataCosting['CALCULATE_DELIVERY']['ERROR'];
			$error_type = $dataCosting['CALCULATE_DELIVERY']['ERROR_TYPE'];
			unset($dataCosting);
			$dataCosting['ERROR'] = $error;
			$dataCosting['ERROR_TYPE'] = $error_type;
		}
		
		return $dataCosting;
	}
	
	/**
	 * Входные параметры: От объекта класса
	 * Суть: Расчет доставки когда больше одного товара, доставка будет считатся со склада где больше всего товаров
	 * Возвращает: Массив с координатами, массив с доставкой если она была просчитана
	*/
	public function getStatusMoreProduct() {
		$dataCosting = array();
		
		$siteId = \Bitrix\Main\Context::getCurrent()->getSite();
		
		#Запрашиваем информацию по складам по всем товарам
		$dataCosting['productList'] = $this->productDataRequest();
		if(!empty($dataCosting['productList'])) {
			#Запрос координат местоположения
			$dataCosting['coordinates'] = $this->locationCoordinates();
			if(!empty($dataCosting['coordinates'])) {
				#Проверяем наличие на складах
				$AmountStoreCheckResult = $this->checkAmountStore($dataCosting['productList']);

				#Удаляем массив складов из результирующего массива
				unset($dataCosting['productList']);

				#Проверяем что все товары есть в наличии
				$dataCosting['HAVE_ALL_AMOUNT'] = $this->checkAllAmountProduct($AmountStoreCheckResult['QUANTITY_LIMIT']);
				$dataCosting['NEED_PRODUCT'] = $AmountStoreCheckResult['QUANTITY_LIMIT'];
				
				if(!empty($AmountStoreCheckResult['STORE_RETURN'])) {
					#Перебираем склады
					$last_store_amount = array();
					foreach($AmountStoreCheckResult['STORE_RETURN'] as $store) {
						if(!empty($last_store_amount)) {
							if($store['AMOUNT_MAX'] > $last_store_amount['AMOUNT_MAX']) {
								$last_store_amount = $store;
							}
						} else {
							$last_store_amount = $store;
						}
					}
					$dataCosting['SUITABLE_DELIVERY'] = 'Y';
					$dataCosting['NEAREST_WAREHOUSE'] = $last_store_amount['STORE_NAME'];
				} else {
					$arField = array('SITE_ID'=>$siteId,'LOCATION'=>$this->location,'PERSONAL_TYPE'=>'1');
					foreach($this->product as $item) {
						$arField['PRODUCT'][] = array('PRODUCT_ID'=>$item,'QUANTITY'=>$this->quantity[$item]);
					}
					$dataCosting['DEFAULT_DELIVERY'] = 'Y';
				}
			} else {
				unset($dataCosting);
				$dataCosting['ERROR'] = 'Нет координат. проверьте соединение с yandex.maps';
				$dataCosting['ERROR_TYPE'] = 'GPS_ERROR';
				return $dataCosting;
			}
		} else {
			unset($dataCosting);
			$dataCosting['ERROR'] = 'Нет товаров, проверьте подключаемый компонент и его параметры';
			$dataCosting['ERROR_TYPE'] = 'PRODUCT_ERROR';
			return $dataCosting;
		}
		
		return $dataCosting;
	}
	
	
	/**
	 * Входные параметры: array $product['VALUE'], array $quantity['PRODUCT_ID' => 'VALUE']
	 * Суть: Расчитываем стоимость доставки по входным данным
	 * Возвращает: Массив с даннами доставки
	*/
	public static function calculateDeliveryNow(array $product, array $quantity, $location) {
		$siteId = \Bitrix\Main\Context::getCurrent()->getSite();
		
		$arField = array('SITE_ID'=>$siteId,'LOCATION'=>$location,'PERSONAL_TYPE'=>'1');
		foreach($product as $item) {
			$arField['PRODUCT'][] = array('PRODUCT_ID'=>$item,'QUANTITY'=>$quantity[$item]);
		}
		
		$returnArray = self::calculateDelivery($arField, false);
		
		return $returnArray;
	}




#Дополнительные методы

	/**
	 * Входные параметры: 
	 * Суть: 
	 * Возвращает: 
	*/
	static function calculateDelivery($arFields, $nearest_warehouse) {
		
		#Кастыль, переводит в глобал код откуда отправлять товар
		if($nearest_warehouse) {
			$oManager = \BXmaker\GeoIP\Manager::getInstance();
			$locationSelect = $oManager->searchLocation($nearest_warehouse);
			$locationSelect = $locationSelect[0];
			define(NEAREST_WAREHOUSE, $locationSelect['location_code']);
		}
		
		$arReturnItems = array();
		
		if (!Loader::includeModule('sale') || !Loader::includeModule('catalog')) {
			return $arReturnItems;
		}

		$order = Sale\Order::create($arFields['SITE_ID']);
		$order->isStartField();
		$order->setPersonTypeId($arFields['PERSONAL_TYPE']);
		
		$order->Algorithm = 'Y';

		#Устанавливаем куда считать доставку
		$props = $order->getPropertyCollection();
		$loc   = $props->getDeliveryLocation();
		$arLoc = Sale\Location\LocationTable::getById($arFields['LOCATION'])->fetch();
		if (!empty($arLoc) && is_object($loc)) {
			$loc->setField('VALUE', $arLoc['CODE']);
		}

		#Создаем корзину
		$basket         = Sale\Basket::create($arFields['SITE_ID']);
		$settableFields = array_flip(Sale\BasketItemBase::getSettableFields());

		#Наполняем корзину
		foreach($arFields['PRODUCT'] as $item) {
			$basketItem = $basket->createItem('catalog', $item['PRODUCT_ID']);
			$basketItem->setField('PRODUCT_PROVIDER_CLASS', 'CCatalogProductProvider');
			$basketItem->setField('QUANTITY', $item['QUANTITY']);
		}
		
		#Переносим корзину в заказ
		$order->setBasket($basket);


		#Создаем отгрузку
		$shipmentCollection     = $order->getShipmentCollection();
		$shipment               = $shipmentCollection->createItem();
		$shipmentItemCollection = $shipment->getShipmentItemCollection();
		$shipment->setField('CURRENCY', $order->getCurrency());


		#Добавляем товары в отгрузку
		foreach ($order->getBasket() as $item) {
			$shipmentItem = $shipmentItemCollection->createItem($item);
			$shipmentItem->setQuantity($item->getQuantity());
		}
		
		#Считаем доставку
		$arDeliveryServiceAll = Delivery\Services\Manager::getRestrictedObjectsList($shipment);
		if (!empty($arDeliveryServiceAll)) {
			foreach ($arDeliveryServiceAll as $deliveryObj) {
				$calcResult = $deliveryObj->calculate($shipment);
				if($calcResult->getPrice() > 0) {
					$arDelivery['SORT']           = $deliveryObj->getSort();
					$arDelivery["PRICE_FORMATED"] = SaleFormatCurrency(round($calcResult->getPrice()), $deliveryObj->getCurrency());
					$arDelivery["CURRENCY"]       = $deliveryObj->getCurrency();
					$arDelivery["PRICE"]          = $calcResult->getPrice();
					$arDelivery["DELIVERY_PRICE"] = roundEx($calcResult->getPrice(), SALE_VALUE_PRECISION);
					$arDelivery["PERIOD_TEXT"]    = $calcResult->getPeriodDescription();
					$arDelivery["PERIOD_FROM"]    = intval($calcResult->getPeriodFrom());
					$arDelivery["PERIOD_TO"]      = intval($calcResult->getPeriodTo());
					$arDelivery["IS_PROFILE"]     = $deliveryObj->isProfile();
				    $arDelivery["NAME"] = $deliveryObj->getName();;
					$arReturnItems[$deliveryObj->getId()] = $arDelivery;
				}
			}
			if(empty($arReturnItems)) {
				$arReturnItems['ERROR'] = 'Не удалось расчитать ни одну ТК';
				$arReturnItems['ERROR_TYPE'] = 'DELIVERY_ERROR';
			}
		}

		return $arReturnItems;
	}

	/**
	 * Входные параметры: широта и долгота первой точки и второй точки
	 * Суть: При помощи формулы Винценти просчитывает растояние вплоть до метра
	 * Возвращает: Возвращает расстояние в км(определено в секции return) можно переопределить на метры
	*/
	public static function calculateTheDistance ($φA, $λA, $φB, $λB) {
		$EARTH_RADIUS = 6372795;
	    #перевести координаты в радианы
	    $lat1 = $φA * M_PI / 180;
	    $lat2 = $φB * M_PI / 180;
	    $long1 = $λA * M_PI / 180;
	    $long2 = $λB * M_PI / 180;
	 
	    #косинусы и синусы широт и разницы долгот
	    $cl1 = cos($lat1);
	    $cl2 = cos($lat2);
	    $sl1 = sin($lat1);
	    $sl2 = sin($lat2);
	    $delta = $long2 - $long1;
	    $cdelta = cos($delta);
	    $sdelta = sin($delta);
	 
	    # вычисления длины большого круга
	    $y = sqrt(pow($cl2 * $sdelta, 2) + pow($cl1 * $sl2 - $sl1 * $cl2 * $cdelta, 2));
	    $x = $sl1 * $sl2 + $cl1 * $cl2 * $cdelta;
	 
	    $ad = atan2($y, $x);
	    $dist = $ad * $EARTH_RADIUS;
	    return $dist/1000;
	}
	
	
	/**
	 * Входные параметры: отсутствуют
	 * Суть: Запрашивает данные по складам конкретного товара
	 * Возвращает: массив складов с товарами
	*/
	public function productDataRequest() {
		$productList = array();

		if(!Loader::includeModule("catalog")) die();
		$rsStore = \CCatalogStoreProduct::GetList(array(), array('PRODUCT_ID' =>$this->product), false, false); 
		while ($arStore = $rsStore->Fetch()){   
			$productList[$arStore['PRODUCT_ID']][] = $arStore;
		}
		
		return $productList;
	}
	
	/**
	 * Входные параметры: отсутствуют
	 * Суть: Запрашивает данные по названию города из гугл или яндекс карт, при обработке, запихивает в свой собственный массив.
	 * Возвращает: Массив с данными $coordinates, названия(data_name) города латиницей 
	 * и координаты(gps) центра города, его правый верхний(northeast) и левый нижний градус(southwest) в формате lat,lng
	*/
	public function locationCoordinates() {
		$coordinates = array();
		
		#Запрос координат у гугла
		#$coordinates = $this->googleCoordinates();
		
		#Запрос координат у яндекс
		$coordinates = $this->yandexCoordinates();
		
		if(!empty($coordinates)) return $coordinates;
		else return false;
		
		
	}
	/**
	 * Входные параметры: отсутствуют
	 * Суть: Запрашивает данные по названию города из гугл карт
	 * Возвращает: Массив с данными $coordinates, названия(data_name) города латиницей 
	 * и координаты(gps) центра города, его правый верхний(northeast) и левый нижний градус(southwest) в формате lat,lng
	*/
	public function googleCoordinates() {
		$coordinates = array();
		#Запрос координат
		$coordinates = file_get_contents('http://maps.google.com/maps/api/geocode/json?address='.$this->city.'&sensor=false');
		#Декодирование в ассоциативный массив
		$coordinates = json_decode($coordinates, true);
		
		if($coordinates['status'] == 'OK') {
			foreach($coordinates['results'][0]['address_components'] as $item) {
				$coordinates['data_name'][] = $item['long_name'];
			}
			$coordinates['gps']['lat'] = $coordinates['results'][0]['geometry']['location']['lat']; #lat - идет с севера (North, N) на юг (South, S).
			$coordinates['gps']['lng'] = $coordinates['results'][0]['geometry']['location']['lng']; #lng - идет с запада (West, W) на восток (East, E).
			$coordinates['gps']['bounds'] = $coordinates['results'][0]['geometry']['bounds'];
			
			unset($coordinates['results']);
			unset($coordinates['status']);
			return $coordinates;
		} else
			return false;
	}

	/**
	 * Входные параметры: отсутствуют
	 * Суть: Запрашивает данные по названию города из яндекс карт
	 * Возвращает: Массив с данными $coordinates, названия(data_name) города латиницей 
	 * и координаты(gps) центра города, его правый верхний(northeast) и левый нижний градус(southwest) в формате lat,lng
	*/
	public function yandexCoordinates() {
		$coordinates = array();
		#Запрос координат
		$coordinates = file_get_contents('https://geocode-maps.yandex.ru/1.x/?format=json&geocode='.$this->city);
		#Декодирование в ассоциативный массив
		$coordinates = json_decode($coordinates, true);
		
		if(!empty($coordinates)) {
			$coordinates['data_name'][] = $coordinates['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['name'];
			$coordinates['data_name'][] = $coordinates['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['description'];
			$coordinates['gps']['point'] = $coordinates['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos']; 	
			$pos = explode(" ", $coordinates['gps']['point']);
			$coordinates['gps']['lat'] = $pos[1];
			$coordinates['gps']['lng'] = $pos[0];
			
			unset($coordinates['gps']['point']);
					
			$coordinates['gps']['bounds'] = $coordinates['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['boundedBy']['Envelope'];

			$pos = explode(' ', $coordinates['gps']['bounds']['lowerCorner']);
			
			$coordinates['gps']['bounds']['southwest']['lat'] = $pos[1];
			$coordinates['gps']['bounds']['southwest']['lng'] = $pos[0];
			
			unset($coordinates['gps']['bounds']['lowerCorner']);
			
			$pos = explode(' ', $coordinates['gps']['bounds']['upperCorner']);
			
			$coordinates['gps']['bounds']['northeast']['lat'] = $pos[1];
			$coordinates['gps']['bounds']['northeast']['lng'] = $pos[0];
			
			unset($coordinates['gps']['bounds']['upperCorner']);
			
			unset($coordinates['response']);
			
			return $coordinates;
		} else
			return false;
	}
	
	/**
	 * Входные параметры: массив складов продукта
	 * Суть: через цикл проверяет наличие товара на складах
	 * Возвращает: Массив с данными QUANTITY_LIMIT - количество продуктов которые удалось списать со склада и STORE_RETURN, массив складов в которых есть наличие
	*/
	function checkAmountStore($product_list) {
		$returnData = array();
		
		#Задаем массив полного лимита на продукты в виде необходимого количества
		$returnData['QUANTITY_LIMIT'] = $this->quantity;

		foreach($product_list as $key_product => $product_item) {
			foreach($product_item as $key_store => $store) {
				if($store['AMOUNT'] > 0) {
					if($returnData['QUANTITY_LIMIT'][$key_product] != 0) {
						if($store['AMOUNT'] >= $returnData['QUANTITY_LIMIT'][$key_product]) {
							#Добавляем имя города склада
							$returnData['STORE_RETURN'][$store['STORE_ID']]['STORE_NAME'] = $store['STORE_NAME'];
							#Добавляем максимальное значение к данному складу
							$returnData['STORE_RETURN'][$store['STORE_ID']]['AMOUNT_MAX'] += $store['AMOUNT'];
								
							if($store['AMOUNT'] == $returnData['QUANTITY_LIMIT'][$store['PRODUCT_ID']]) {
								#Количество равно
								$returnData['STORE_RETURN'][$store['STORE_ID']]['AMOUNT'] += $store['AMOUNT'];
								$returnData['QUANTITY_LIMIT'][$key_product] = 0;
							} elseif($store['AMOUNT'] < $returnData['QUANTITY_LIMIT'][$key_product]) {
								#Количество меньше
								$returnData['STORE_RETURN'][$store['STORE_ID']]['AMOUNT'] += $store['AMOUNT'];
								$returnData['QUANTITY_LIMIT'][$key_product] = $returnData['QUANTITY_LIMIT'][$key_product] - $store['AMOUNT'];
							} else {
								#Количество больше
								$returnData['STORE_RETURN'][$store['STORE_ID']]['AMOUNT'] += $returnData['QUANTITY_LIMIT'][$key_product];
								$returnData['QUANTITY_LIMIT'][$key_product] = 0;
							}
						}
					}
				}
			}
		}
		return $returnData;
	}
	
	/**
	 * Входные параметры: Массив с данными QUANTITY_LIMIT - количество продуктов которые удалось списать со склада
	 * Суть: Проверяет удалось ли найти все продукты на складах, если количество равно 0
	 * Возвращает: Статус Y - все продукты в наличии и N - есть продукты которых нет в наличии
	*/
	function checkAllAmountProduct($quantity_limit) {
		$haveAllAmount = 'Y';
		
		foreach($quantity_limit as $item) {
			if($item > 0) {
				$haveAllAmount = 'N';
				break;
			}
		}
		return $haveAllAmount;
	}
}
?>