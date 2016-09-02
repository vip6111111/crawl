<?php
namespace library;

class CrawlCallback
{

    /**
     * 写入库
     *
     * @param unknown $row
     * @param unknown $db
     * @param unknown $v
     */
    public static function detailWrite($row, $db, $v)
    {
        if (empty($v['content']))
            continue;
        $v['url'] = trim($v['url']);
        $data = array();
        $dataDetail = array();
        $dataId = md5($v['url']);
        foreach ($row['data'] as $configKey => $config) {
            $pattern = $config['rule'];
            preg_match($config['rule'], $v['content'], $match);
            if (! empty($match[1])) {
                // 是否要匹配图片
                if ($config['match_img'] == '2') {
                    $data[$config['field']] = trim(strip_tags(htmlspecialchars_decode($match[1]), $config['allowable_tags']));
                } else {
                    $data[$config['field']] = trim(strip_tags(htmlspecialchars_decode($match[1]), $config['allowable_tags']));
                }
            }

            $dataDetail[] = array(
                'data_id' => $dataId,
                'site_id' => $row['id'],
                'name' => $config['field'],
                'value' => isset($data[$config['field']]) ? $data[$config['field']] : ''
            );
        }

        $set = array(
            'site_id' => $row['id'],
            'url' => $v['url'],
            'data_id' => $dataId,
            'title' => isset($data['title']) ? $data['title'] : '',
            'create_time' => date("Y-m-d H:i:s")
        );
        $result = $db->insert('data', $set);
        if ($result) {
            $db->insertAll('data_detail', $dataDetail);
        }
    }

    public static function listWrite($row, $db, $v)
    {
        if (empty($v['content']))
            continue;

        preg_match_all($row['item_rule_a'], $v['content'], $match);
        $filename = $row['project'] . '/detail.txt';
        if (empty($match[1])) {
           echo ('not match: url:' . trim($v['url']) . $row['item_rule_a']);
            return false;
        }
        $urlArr = [];
        foreach ($match[1] as $k2=>$v2) {
            $urlArr[$k2]['url'] = $v2;
        }

        $result = \library\Crawl::write($urlArr, $filename);
        if(empty($result)){
            echo("not data !");
            return false;
        }
        $data = [];
        foreach ($result as $v){
            $data[] = array(
                'url'      => $v['url'],
                'filesize' => $v['filesize'],
                'site_id'  => $row['id'],
                'type'     => 1
            );
        }

        return $db->insertAll('url', $data);

    }
}

