<?php

class Catalog
{
    const GET_FIELDS = ['id', 'pagetitle', 'uri', 'price', 'price_old', 'image'];
    const SEARCH_QUERY = "(
        IF (`pagetitle` LIKE 'search%', 10, 0) +
        IF (`pagetitle` LIKE '% search %', 9, 0) +
        IF (`pagetitle` LIKE '% search%', 8, 0) +
        IF (`pagetitle` LIKE '%search%', 7, 0) +
        IF (`introtext` LIKE '%search%', 5, 0) +
        IF (`content` LIKE '%search%', 1, 0)
    ) AS `relevant`";


    /**
     * 
     */
    public static function request($action)
    {	
        switch ($action) {
            case 'liveSearch':
                return self::liveSearch($_REQUEST['query'], $_REQUEST['mode']);
            case 'getOneFull':
                return self::getOneFull((int)$_REQUEST['id']);
        }
    }


    /**
     * Получить товар
     */
    public static function getOne(int $id)
    {
        $q = self::getMany([
            'id' => $id,
            'limit' => 1
        ]);
        return $q['rows'][0] ?? false;
    }


    /**
     * Получить все поля товара
     */
    public static function getOneFull(int $id)
    {
        $cache = Shop::fromCache("fullproduct", $id, 'resource');
        if ($cache) return self::countSavedProducts($cache);

        global $modx;
        $resFields = array_keys($modx->getFields('modResource'));

        $qres = $modx->newQuery('modResource');
        $qres->select($resFields);
        $qres->where([
            'modResource.id' => $id
        ]);
        $qres->prepare();
        $qres->stmt->execute();
        $res = $qres->stmt->fetch(PDO::FETCH_ASSOC);

        if (empty($res)) return false;

        $q = $modx->newQuery('modTemplateVarResource');
        $q->select([
            'modTemplateVarResource.value',
            'tv.name',
        ]);
		$q->innerJoin('modTemplateVar', "tv", "modTemplateVarResource.tmplvarid = tv.id");

        $q->where([
            'modTemplateVarResource.contentid' => $id
        ]);

        $q->prepare();
        $q->stmt->execute();
        $tvs = $q->stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tvs as $tv) {
            $res[$tv['name']] = $tv['value'];
        }

        $products = self::processProducts([$res]);
        Shop::toCache($products[0], "fullproduct", $id, 'resource');
        return self::countSavedProducts($products[0]);
    }


    /**
     * Получить товары
     */
    public static function getMany(array $props = [], $pagination = false)
    {
        $cacheKey = 'products_' . ($pagination ? 'pg' : 'nopg');
        $cache = Shop::fromCache($cacheKey, $props, 'resource');
        if ($cache) return self::countSavedProducts($cache);


        $time_start = microtime(true);
        global $modx;

        $cfg = Params::cfg();
        $select = $having = $joins = [];

        $getFields = array_merge(self::GET_FIELDS, ($cfg['product_get_fields'] ?? []));
        $resFields = array_keys($modx->getFields('modResource'));
        $tvsFields = self::tvsMap();
        
        
        $where = array_merge([
            'published = 1',
            'deleted = 0',
            'template IN (' . implode(',', $cfg['product_tmpls']) .')'
        ], $props['where'] ?? []);

        
        /* сортировка */
        $sortClass = !empty($props['selections']) ? 'sel' : 'modResource';
        $sortField = "`$sortClass`.`menuindex`";
        $sortDir = 'ASC';
        if (!empty($props['sort'])) {
            $sort = explode('-', $props['sort']);

            if (array_key_exists($sort[0], $tvsFields)) {
                //TODO проверять тут тип tv
                $sortField = "`tv_{$sort[0]}`.`value` * 1";
            } elseif (in_array($sort[0], $resFields)) {
                $sortField = "`modResource`.`{$sort[0]}`";
            }

            $getFields[] = $sort[0];
            $sortDir = !empty($sort[1]) && $sort[1] == 'desc' ? 'DESC' : 'ASC';
        }

        
        $limit = (int)($props['limit'] ?? 12);
        $page = (int)($props['page'] ?? 1);
        $offset = $limit * ($page - 1);


        /* Поиск */
        $search = trim($props['search'] ?? '');
        if (strlen($search)) {
            $select[] = str_replace('search', $search, self::SEARCH_QUERY);
            $having[] = "relevant>0";
            $sortField = 'relevant';
            $sortDir = 'DESC';
        }

        /* Фильтры по tv */
        foreach ($props as $key => $value) {

            foreach (['min_', 'max_'] as $m) {
                if (strpos($key, $m) !== false) {
                    $field = str_replace($m, '', $key);
                    if (array_key_exists($field, $tvsFields)) {
                        $value = (int)$value;
                        if ($value) {
                            $inq = $m == 'max_' ? '<=' : '>=';
                            $where[] = "(tv_{$field}.value $inq $value)";
                            $getFields[] = $field;
                        }
                    }
                }
            }

            if (strpos($key, 'filter_') !== false) {
                $field = str_replace('filter_', '', $key);
                
                if (array_key_exists($field, $tvsFields)) {
                    $values = gettype($value) == 'array' ? $value : explode(',', $value);
                    $tmp = [];
                    foreach ($values as $v) {
                        if (strlen((string)$v)) {
                            $tmp[] = "( CONCAT('||', tv_{$field}.value, '||') LIKE '%||$v||%' )";
                        }
                    }
                    $where[] = '(' . implode(' OR ', $tmp) . ')';
                    $getFields[] = $field;
                }
            }

            if ($key == 'id') $where[] = "(modResource.id = $value)";
        }

        //$where[] = "JSON_CONTAINS(`variables`.`value`, '{\"size\":\"45\"}') > 0";

        $getFields = array_unique($getFields);
        $where = array_diff($where, ['']);

        $jtvs = self::getQuerySelect($getFields, $resFields, $tvsFields);
        $select = array_merge($select, $jtvs['select']);
        $leftJoins = $jtvs['joins'] ?: [];
        $innerJoins = []; 

        /* Разделы каталога */
        if (!empty($props['section'])) {
            $ids = self::getChildIds($props['section']);

            if (!empty($props['selections'])) {
                $innerJoins[] = ['CollectionSelection', 'sel', 'modResource.id = sel.resource AND sel.collection IN (' .implode(',', $ids). ')'];
            } else {
                $where[] = 'parent IN (' . implode(',', $ids) .')';
            }
            
        }

        /* Основной запрос */
        $q = $modx->newQuery('modResource');
        $q->select($select);
        $q->limit($limit, $offset);
        $q->where($where);
        $q->sortby($sortField, $sortDir);
        
        foreach ($leftJoins as $join) {
            $q->leftJoin($join[0], $join[1], $join[2]);
        }
        foreach ($innerJoins as $join) {
            $q->innerJoin($join[0], $join[1], $join[2]);
        }
        if ($having) {
            $q->having($having);
        }
        $q->prepare();
        $q->stmt->execute();
        $rows = $q->stmt->fetchAll(PDO::FETCH_ASSOC);

        /* Считаем кол-во, если оно может быть больше лимита */
        $total = count($rows);
        if ($pagination && ($total == $limit || $page > 1)) {
            $qt = $modx->newQuery('modResource');
            $qt->where($where);
            $qt->select($select);
            if ($having) {
                $qt->having($having);
            }
            foreach ($leftJoins as $join) {
                $qt->leftJoin($join[0], $join[1], $join[2]);
            }
            foreach ($innerJoins as $join) {
                $qt->innerJoin($join[0], $join[1], $join[2]);
            }
            if ($qt->prepare() && $qt->stmt->execute()) {
                $total = $qt->stmt->rowCount();
            } 
        }
        
        $out = [
            'rows' => self::processProducts($rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
        ];

        $out['debug'] = [
            'time' => microtime(true) - $time_start,
            'where' => $where,
            'sql' => $q->toSQL(),
            'select' => $getFields,
            'props' => $props
        ];
        if ($total) {
            Shop::toCache($out, $cacheKey, $props, 'resource');
        }
        
        return self::countSavedProducts($out);
    }


    /**
     * присоединяем нужные tv, выбираем нужные поля
     */
    private static function getQuerySelect(array $getFields, array $resFields, array $tvsFields)
    {
        list($select, $joins) = [];
        foreach ($getFields as $field) {
            if (in_array($field, $resFields)) {
                $select[] = "modResource.{$field} as $field";
            } elseif (array_key_exists($field, $tvsFields)) {
                $tmplvarid = $tvsFields[$field]['id']; 
                $joins[] = [
                    'modTemplateVarResource',
                    "tv_{$field}",
                    "modResource.id = `tv_{$field}`.`contentid` AND `tv_{$field}`.`tmplvarid` = {$tmplvarid}"
                ];
                $select[] = "IFNULL(`tv_{$field}`.`value`, '') as {$field}";
            }
        }
        return [
            'select' => $select,
            'joins' => $joins,
        ];
    }


    /**
     * 
     */
    public static function processProducts(array $rows)
    {
        global $modx;
        /* путь до картинок */
        $source_id = $modx->getOption('default_media_source',null,1);
        $modx->loadClass('sources.modMediaSource');
        $source = modMediaSource::getDefaultSource($modx,$source_id);
        $source_properties = $source->getProperties();
        $img_path = $source_properties['basePath']['value'];

        foreach ($rows as &$item) {
            $item['name'] = htmlspecialchars($item['pagetitle']);
            unset($item['pagetitle']);
            $item['_price'] = self::numFormat($item['price']);
            if (!empty($item['image'])) {
                $item['image'] = "$img_path{$item['image']}";
            }
            if (!empty($item['price_old'])) {
                $item['discount'] = 1 - ((int)$item['price'] / (int)$item['price_old']);
                $item['discount'] = round($item['discount'] * 100);
                $item['_price_old'] = self::numFormat($item['price_old']);
            }
        }
        unset($item);

        return $rows;
    }


    /**
     * 
     */
    private static function countSavedProducts(array $input)
    {
        $rows = $input['rows'] ?? $input;
        
        $cart = $_SESSION['shop_cart']['items'] ?? [];
        $cartIds = array_column($cart , 'id');

        foreach ($rows as &$item) {
            $index = array_search($item['id'], $cartIds);
            $cartQty = $index !== false && isset($cart[$index]) ? $cart[$index]['qty'] : 0;
            $item['inCart'] = $cartQty;
        }
        unset($item);

        if (empty($input['rows'])) {
            return $rows;
        } else {
            $input['rows'] = $rows;
            return $input;
        }
    }



    /**
     * Получаем все TV
     */
    public static function tvsMap()
    {
        $cache = Shop::fromCache('shop_all_tvs');
        if ($cache) return $cache;

        global $modx;
        $out = [];

        $q = $modx->newQuery('modTemplateVar');
        $q->select(['id', 'name', 'type']);
        $q->sortby('rank', 'ASC');
        $q->prepare();
        $q->stmt->execute();
        $tvs = $q->stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tvs as $key => $tv)  {
            $out[$tv['name']]['id'] = (int)$tv['id'];
            $out[$tv['name']]['type'] = $tv['type'];
        }

        Shop::toCache($out, 'shop_all_tvs');
        return $out;
    }


    /**
     * 
     */
    public static function getChildIds($parent)
    {
        global $modx;
        $cfg = Params::cfg();
        // todo другие контексты
        $parents = $modx->getChildIds($parent, 6, ['context' => 'web']);
        $parents[] = $parent;

        $q = $modx->newQuery('modResource');
        $q->select('id');
        $q->where([
            'id:IN' => $parents,
            'template:IN' => $cfg['section_tmpls'],
        ]);
        $q->prepare();
        $q->stmt->execute();
        $ids = $q->stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return $ids ?: [];
    }

    
    /**
     * 
     */
    private static function numFormat($input)
    {
        $input = floatval(str_replace([' ',','], ['','.'], $input));
        return number_format($input,(floor($input) == $input ? 0 : 2),'.',' ');
    }
    

    /**
     * Быстрый поиск
     */
    private static function liveSearch($q, $mode = 'default')
    {
        $search = trim($q);

        $cache = Shop::fromCache("livesearch-{$mode}-", $search, 'resource');
        if ($cache) return $cache;

        $time_start = microtime(true);
        $cfg = Params::cfg();
        global $modx;
        $out = ['rows' => []];
        $rows = [];

        
        $q = $modx->newQuery('modResource');
        $q->select([
            str_replace('search', $search, self::SEARCH_QUERY),
            '`modResource`.`pagetitle` as `name`',
            '`modResource`.`uri`',
            '"product" as "type"'
        ]);
        $q->limit(10);
        $q->where([
            'template:IN' => $cfg['product_tmpls'],
            'deleted' => 0, 'published' => 1
        ]);
        $q->sortby('relevant', 'DESC');
        $q->having("relevant>0");
        if ($q->prepare() && $q->stmt->execute()) {
            $rows = $q->stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        if ($mode != 'products') {
            $q2 = $modx->newQuery('modResource');
            $q2->select([
                str_replace('search', $search, self::SEARCH_QUERY),
                '`modResource`.`pagetitle` as `name`',
                '`modResource`.`uri`',
                '"category" as "type"'
            ]);
            $q2->limit(10);
            $q2->where([
                'template:IN' => $cfg['section_tmpls'],
                'deleted' => 0, 'published' => 1
            ]);
            $q2->sortby('relevant', 'DESC');
            $q2->having("relevant>0");
            if ($q2->prepare() && $q2->stmt->execute()) {
                $rows2 = $q2->stmt->fetchAll(PDO::FETCH_ASSOC);
                $rows = array_merge($rows, $rows2);
            }
        }

        $out['rows'] = $rows;
        $out['debug'] = [
            'time' => microtime(true) - $time_start,
            'q' => $search
        ];

        Shop::toCache($out, "livesearch-{$mode}-", $search, 'resource');
        return $out;
    }


    
}