#!/usr/bin/php
<?php
/**
 * 使い方 : ./link_check.php [URL] [User agent]
 * [user agent] s: Android / a: iPhone / i: Docomo FP / e: AU FP / y: SoftBank FP\n\n";
 *
 * 指定されたURLの外部ドメインは再帰的には辿らない
 * 一度チェックしたURLはチェックしない
 * 指定された深さ以下はチェックしない
 * チェックした結果を各ページ毎に存在するリンク切れURLのリストという形でメールする
 */


//子URLから除外するURLパターンのリスト
$exclude_urls = array();
$exclude_urls[] = "mailto:";
$exclude_urls[] = "^#";
$exclude_urls[] = "javascript:void";
$exclude_urls[] = "confon:\\/\\/";
$exclude_urls[] = "http:\\/\\/menu2001\\.ezweb\\.ne\\.jp\\/auinfo\\/chargeinfo\\.html";
$exclude_urls[] = "http:\\/\\/auth\\.collect\\.kddi\\.com\\/mob\\/KSReq";
$exclude_urls[] = "pakehocheck\\.php";

define("EXCLUDE_CHILD_URLS", serialize($exclude_urls));

//子URLから削除するパラメータのリスト
//define("EXCLUDE_CHILD_URL_QUERYS", serialize(array("t", "nl", "ccd", "back_url", "pr","ret")));
define("EXCLUDE_CHILD_URL_QUERYS", serialize(array("t", "nl", "ccd", "back_url", "pr","ret")));

//チェックする最大階層の深さ
define('MAX_DEPTH', 3);

//HTTPSのみを許容するか？
define('IS_HTTPS_ONLY', true);

//20xレスポンスのみ許容するか？                                          k
define('IS_20X_ONLY', true);

//タイムアウト値(秒)
define('TIMEOUT_SEC',4);


//レポートメールを送信するアドレス
//define("REPORT_TO", "report_to@hogehogehoge.com");
//レポートメールの送信元アドレス
//define("REPORT_FROM", "Link Check Report <report_from@hogehoge.com>");

// メイン処理 ============================================================

//パラメータチェック
$target_url = process_argv($argv);

stream_context_set_default(array(
    'http' => array(
        'timeout' => TIMEOUT_SEC
    )
));
date_default_timezone_set('Asia/Tokyo');


$message = check_links($target_url);
if ($message) {
    put_report($target_url, $message);
    file_put_contents("./link_check_".CARRIER_ID.".txt", $message);
} else {
    rlog("NO DEAD LINK!");
}

return;


// 関数定義 ==============================================================

/**
 * 引数からURLとuser agentを取得する
 * user agent関連はグローバル的に設定する
 * NGの場合は、この関数内で終了する
 * @param $argv
 * @return array
 */
function process_argv($argv)
{
    if (count($argv) < 3) {
        echo "\nUsage\n";
        echo "link_check.php [target url] [user agent]\n";
        echo "[user agent] s: Android / a: iPhone / i: Docomo FP / e: AU FP / y: SoftBank FP / pc: PC(firefox)\n\n";
        exit;
    }

    $target_url = $argv[1];
//    if (strpos($target_url, "http", 0) !== 0) {
//        echo "target url is must be [http://...]\n";
//        exit;
//    }
    if (exists_url($target_url) !== TRUE) {
        echo "Can't access target url.\n";
        exit;
    }

    $carrier_id = $argv[2];

    //キャリアIDは処理の間不変なのでグローバルに扱う
    define('CARRIER_ID', $carrier_id);
    get_context();

    return $target_url;
}

/**
 * レポートを出力する
 *
 * @param $target_url
 * @param $message
 */
function put_report($target_url, $message)
{
    if (!$target_url || !$message) {
        //データ無しのため処理終了
        return;
    }

    $today = date('Y/m/d');

    switch (CARRIER_ID) {
        case "s":
            $user_agent = "Android";
            break;
        case "a":
            $user_agent = "iPhone";
            break;
        case "i":
            $user_agent = "Docomo ガラケー";
            break;
        case "e":
            $user_agent = "AU ガラケー";
            break;
        case "y":
            $user_agent = "SoftBank ガラケー";
            break;
        case "pc":
            $user_agent = "PC(firefox)";
            break;
        default:
            $user_agent = CARRIER_ID;
    }

    $subject = "{$today} リンク切れレポート:{$target_url}:{$user_agent}";
    $body = "サイト:{$target_url}\n";
    $body .= "対象  :{$user_agent}\n";
    $body .= "以下のリンク切れが見付かりました。\n\n" . $message;
    $body .= "\n------------------------------------\n";
    $body .= "以上\n";


    mb_language("Japanese");
    mb_internal_encoding("SJIS");
    $body = mb_convert_kana($body, "KVa");

    $mailfrom="From:".REPORT_FROM;

    mb_send_mail(REPORT_TO, $subject, $body, $mailfrom);
}

/**
 * テキスト中からURLを取り出す
 * @param $target_str 解析するテキスト
 * @return    取り出したURLの配列
 */
function get_urls($target_str)
{
    $patterns = get_target_url_patterns();
    $exclude_pattern = get_exclude_url_pattern();

    $i = 0;
    $urls = array();
    foreach ($patterns as $p) {
        //マッチするすべての部分文字列を取り出す
        if (preg_match_all($p, $target_str, $matches, PREG_SET_ORDER) < 1) {
            continue;
        }
        foreach ($matches as $key => $val) {
            $url = $matches[$key][2];
            if (preg_match($exclude_pattern, $url,$m)) {
                continue;
            }
            $urls[$i] = $url;
            $i++;
        }
    }
    return $urls;
}

/**
 * 除外するURLのパターンを取得する
 * @return string
 */
function get_exclude_url_pattern()
{
    static $exclude_pattern = null;
    if ($exclude_pattern) {
        return $exclude_pattern;
    }
    $excludes = unserialize(EXCLUDE_CHILD_URLS);
    $exclude_pattern = '/' . implode("|", $excludes) . "/";
    rlog("exclude pattern=" . $exclude_pattern);
    return $exclude_pattern;
}

/**
 * 対象とするURLを抽出するためのパターン
 * @return array
 */
function get_target_url_patterns()
{
    static $patterns = null;;
    if ($patterns) {
        return $patterns;
    }

    $patterns = array();
    $patterns[] = '/<a(.*)href=\"?([\-_\.\!\~\*\'\(\)a-z0-9\;\/\?\:@&=\+\$\,\%\#]+)\"/i';
    $patterns[] = '/<img(.*)src="?([\-_\.\!\~\*\'\(\)a-z0-9\;\/\?\:@&=\+\$\,\%\#]+)"/i';
    $patterns[] = '/<link(.*)href="?([\-_\.\!\~\*\'\(\)a-z0-9\;\/\?\:@&=\+\$\,\%\#]+)"/i';
    $patterns[] = '/<script(.*)src="?([\-_\.\!\~\*\'\(\)a-z0-9\;\/\?\:@&=\+\$\,\%\#]+)"/i';

    rlog("target_patterns=");
    rlog($patterns);

    return $patterns;
}

/**
 * URLを正規化する（相対指定を絶対指定に変換する）
 * @param    string $url 正規化するURL
 * @param    string $parent_url 読み込んだコンテンツのURL
 * @return    string 正規化したURL / FALSE(正規化に失敗)
 */
function get_formatted_absolute_url($url, $parent_url)
{
    //$parent_url = rtrim($parent_url, '/'); //親URLの最後のスラッシュは除く

    //相対指定の場合
    if (!preg_match('/\:\/\//', $url)) {
        $regs = parse_url($parent_url);
        $regs_path = (isset($regs["path"])) ? $regs["path"] : "";
        $dirname = dirname($regs_path . "dummy");
        if (strpos($url, "//") === 0) {
            //子URLの始めが//の場合は、schemeを連結する(こんな指定あり？)
            $url = $regs["scheme"] . ":" . $url;
        } else if (strpos($url, "/") === 0) {
            //子URLの始めが/の場合は、親URLのホストと直接連結する
            $url = $regs["scheme"] . "://" . $regs["host"] . $url;
        } else {
            $url = $regs["scheme"] . "://" . $regs["host"] . $dirname . "/" . $url;
        }
    }

    //相対指定を絶対指定に変換する
    $regs = parse_url($url);
    $aa = explode('/', $regs["path"]);
    $an = count($aa);
    $bb = array();
    $bn = 0;
    for ($i = 1; $i < $an; $i++) {
        switch ($aa[$i]) {
            case ".":
                break;
            case "..":
                $bn--;
                if ($bn < 0) return FALSE;
                break;
            default:
                $bb[$bn] = $aa[$i];
                $bn++;
                break;
        }
    }
    $ss = "";
    for ($i = 0; $i < $bn; $i++) {
        $ss = $ss . "/" . $bb[$i];
    }

    $s = $regs["scheme"] . "://" . $regs["host"];
    //もし$ssが/のみでないのなら追加 http://mfplus.jp/をhttp://mfplus.jpにするため
    if ($ss != "/") {
        $s .= $ss;
    }

    //クエリストリングから不要なパラメータを取り除く
    $regs_query = (isset($regs["query"])) ? $regs["query"] : "";
    $query = remove_unnecessary_param($regs_query);

    if ($query) {
        $s .= "?" . $query;
    }
    return $s;
}

/**
 * クエリストリングから不要なパラメータを取り除く
 * t=xxは不要。
 *
 * @param $query
 * @return string
 */
function remove_unnecessary_param($query)
{
    if (!$query) {
        return null;
    }
    //アンサニタイズ
    $query = htmlspecialchars_decode($query);
    //?data_id=450?t=735みたいに変にt=がくっついている場合があるので除去する:MFP独自
    $query = preg_replace('/\?t=[0-9]{3}/', '', $query);
    // tはランダムパラメータなのでいらん
    parse_str($query, $query_array);

    //不要なパラメータを取り除く
    $excludes = unserialize(EXCLUDE_CHILD_URL_QUERYS);
    foreach ($excludes as $e) {
        unset($query_array[$e]);
    }

    $query = http_build_query($query_array);
    return $query;
}

/**
 * リンク切れを検査、表示する。
 * チェックして既にOKだったURLとNGだったURLを保存しておいて
 * 次回以降のチェック時にアクセスする手間を省く。
 * もうチェックしていないURLが無いか、規定のdepthまで検査が完了した場合終了する
 *
 * @param $parent_url 検査対象URL
 * @param int $current_depth
 * @return  array($ok_urls,$ng_urls)
 *           $ok_url チェックOKだったURLの配列
 *           $ng_url チェックNGだったURLの配列
 */
function check_links($parent_url, $current_depth = 0)
{

    rlog("check url > {$parent_url}");

    // 既にチェックOK URLの配列
    static $already_ok_urls = array();
    // 既にチェックNG URLの配列
    static $already_ng_urls = array();
    // 既にチェック済の親URLの配列
    static $already_checked_parent_urls = array();
    // 最終的に出力するメッセージ
    static $message = "";


    //既にチェック済のURLなら抜ける
    if (array_search($parent_url, $already_checked_parent_urls) !== FALSE) {
        rlog("already checked.");
        return;
    }

    //チェック対象のURLでなければ抜ける
    if (!is_check_target($parent_url)) {
        rlog("Not check target url.");
        $already_ng_urls[] = $parent_url;
        return FALSE;
    }

    rlog("current depth=".$current_depth);
    if ($current_depth > MAX_DEPTH) {
        rlog("Max depth reached.:" . $current_depth);
        return;
    }

    /*
        //TODO:暫定抜け処理
        static $end_count = 0;
        $end_count ++;
        if($end_count > 30){
            return $message;
        }
    */

    $title = get_title($parent_url);

    //チェック対象URLはチェックOKURLに含める
    $already_ok_urls[] = $parent_url;
    $already_checked_parent_urls[] = $parent_url;

    $child_urls = get_child_urls($parent_url);
    if (!$child_urls) {
        rlog("No link found.");
        return FALSE;
    }

    list($ok_urls, $ng_urls) = validate_child_urls($child_urls, $already_ok_urls, $already_ng_urls);

    if (!empty($ng_urls)) {
        rlog("+++NG URL");
        rlog($ng_urls);
    }

    if (!empty($ng_urls)) {
        $message .= "------------------------------------\n";
        $message .= "TITLE:{$title}\n";
        $message .= "URL  :{$parent_url}\n";
        $message .= "  " . implode("\n  ", $ng_urls);
        $message .= "\n";
    }

    if(empty($ok_urls)){
        rlog("ok url not found.");
        return $message;
    }

    foreach ($ok_urls as $ok_url) {
        //親URLとホストが一致しないURLは外部のURLなのでその先のリンクチェックはしない
        if (!is_same_host($parent_url, $ok_url)) {
            rlog("This is external URL:" . $ok_url);
            continue;
        }

        check_links($ok_url, $current_depth + 1);
    }
    rlog("all check done.");

    return $message;
}

/**
 * 子URLが存在するかどうかチェックする
 *
 * @param $child_urls   チェックする子URL
 * @param $already_ok_urls 全てのチェックOKだったURLの配列
 * @param $already_ng_urls 全てのチェックNGだったURLの配列
 * @return array lisg (ok_url,ng_url)
 *          ok_url ... 今回チェックOKだった配列
 *          ng_url ... 今回チェックNGだった配列
 */
function validate_child_urls($child_urls, &$already_ok_urls, &$already_ng_urls)
{
    $ok_urls = array();
    $ng_urls = array();

    //処理のインジケーターとして"+"を子URLの数だけ表示する
    for ($i = 0; $i < count($child_urls); $i++) echo "-";
    echo "\n";

    foreach ($child_urls as $url) {
        //既にチェックOKのURLに含まれているなら、OK
        if (array_search($url, $already_ok_urls) !== FALSE) {
            echo ":";
            $ok_urls[] = $url;
            continue;
        }

        //既にチェックNGの配列に含まれているなら、NG
        if (array_search($url, $already_ng_urls) !== FALSE) {
            echo "+";
            $ng_urls[] = $url;
            continue;
        }

        //実際にアクセスしてチェック
        $status = exists_url($url);
        if ($status === TRUE) {
            echo ".";
            $ok_urls[] = $url;
            $already_ok_urls[] = $url;
            continue;
        }

        echo "x";
        $ng_urls[] = $url . " " . $status;
        $already_ng_urls[] = $url;
    }
    echo "\n";

    //念の為重複を削除
    $already_ok_urls = array_unique($already_ok_urls);
    $already_ng_urls = array_unique($already_ng_urls);

    return array($ok_urls, $ng_urls);
}

/**
 * 同一ホストのURLかどうかチェックする
 * @param $parent_url 親URL
 * @param $child_url 子URL
 * @return bool true 一致 / false 不一致
 */
function is_same_host($parent_url, $child_url)
{
    if (!$parent_url || !$child_url) return FALSE;

    $pa = parse_url($parent_url);
    $ca = parse_url($child_url);

    if ($pa['host'] == $ca['host']) {
        //ホスト部が一致
        return TRUE;
    }

    return FALSE;
}

/**
 * チェック対象のURLなのか確認する
 * クエリストリングはとって確認する
 * チェック対象:php,htm,html,/で終わる,ドメインのみ指定
 */
function is_check_target($url)
{
    if (!$u = parse_url($url)) {
        // URLではない
        return FALSE;
    }
    if (preg_match('/\.php$|\.htm$|\.html$|\/$/i', $u['path'])) {
        //パスがチェック対象のものである
        return TRUE;
    }
    if ($u['host'] && empty($u['path'])) {
        //ドメインのみ指定されている
        return TRUE;
    }

    return FALSE;
}

/**
 * parent_urlページに存在するリンクのURLを返す。
 * 相対URLは絶対URLにする。
 * 重複URLは削除する。
 *
 * @param $parent_url
 * @return array
 */
function get_child_urls($parent_url)
{
    $parent_url = get_real_parent_url($parent_url);

    $context = get_context();
    $lines = file($parent_url, null, $context);
    rlog("The number of lines=" . count($lines));

    if (empty($lines)) {
        rlog("cant open!:>" . $parent_url . "<");
        return FALSE;
    }

    $child_urls = array();
    $formatted_child_urls = array();
    //parent_urlページを1行づつ読み込んでURLを抽出する
    foreach ($lines as $str) {
        unset($child_urls);
        $child_urls = get_urls($str);
        if (empty($child_urls)) {
            continue;
        }
        //抽出した全てのURLを絶対URLに整形する
        foreach ($child_urls as $child_url) {
            if ($formatted_child_url = get_formatted_absolute_url($child_url, $parent_url)) {
                $formatted_child_urls[] = $formatted_child_url;
            }
        }
    }

    //重複したURLを削除
    return array_unique($formatted_child_urls);
}

/**
 * 真の親URLを取得する
 * (リダイレクトされた場合のためにリダイレクト先のURLを返す)
 * @param $parent_url 親URL
 * @return string 真の親URL
 */
function get_real_parent_url($parent_url)
{
    $headers = @get_headers($parent_url);

    // ステータスコード3xx以外なら、$urlは正しい親URLなのでそのまま返す
    if (!preg_match('/3[0-9]{2}/', $headers[0])) {
        return $parent_url;
    }

    $redirect_url = "";
    foreach ($headers as $h) {
        //最後のLocationヘッダの中身を取得
        if (preg_match('/Location: (.+)/', $h, $match)) {
            $redirect_url = $match[1];
        }
    }

    //httpで始まっているなら真の親URLと見なす
    if (strpos($redirect_url, "http", 0) === 0) {
        return $redirect_url;
    }

    return $parent_url;
}

/**
 * タイトルタグの中身を取得する
 * @param $url タイトルタグを取得するURL
 * @return タイトルタグの中身
 */
function get_title($url)
{
    if (!$url) {
        return null;
    }

    $context = get_context();
    $contents = file_get_contents($url, null, $context);

    if (preg_match('/<title>(.+)<\/title>/', $contents, $match)) {
        return $match[1];
    }

    return null;
}

/**
 * ログる
 * @param $str
 */
function rlog($str)
{
    //admin01上でcannotseek on a pipe..とwarningが出るので抑制
    @error_log(date("Y/m/d H:i:s ") . print_r($str, true) . "\n", 3, "php://stdout");
}

/**
 * URLが存在するかどうか確認する
 * ステータスコード 2xx,3xx以外が返った場合、存在しないものとみなす。
 * エラーの時、ステータスコードを返すので、比較時には === を使用して下さい。
 *
 * @param $url 調査するURL
 * @return bool true:存在する / エラーの時：返ってきたステータスコード
 */
function exists_url($url)
{

    if(https_contents_check($url) !== true){
        rlog($url);
        rlog("NOT HTTPS CONTENTS.");
        return "NOT HTTPS CONTENTS.";
    }

    $headers = @get_headers($url);

    if (IS_20X_ONLY) {
        //2xxの場合のみtrue
        $matches = '/2[0-9]{2}/';
    } else {
        //2xx,3xxの場合のみtrue
        $matches = '/[2-3][0-9]{2}/';
    }

    if (preg_match($matches, $headers[0])) {
        return TRUE;
    }
    rlog($url);
    rlog($headers[0]);
    return $headers[0];
}

/**
 * js,css,画像などhttpsの時、httpsで読み込まれなければならないものがhttpsかどうかチェックする
 * @param $url
 * @return bool
 */
function https_contents_check($url)
{
    if (!IS_HTTPS_ONLY) {
        return true;
    }

    $path = parse_url($url, PHP_URL_PATH);
    $path_parts = pathinfo($path);
    if (!preg_match('/js$|css$|png$|jpeg$|jpg$|gif$/i', $path_parts['extension'])) {
        return true;
    }

    if (strpos($url, "https:", 0) !== 0) {
        return false;
    }

    return true;
}


/**
 * httpアクセス時のコンテキストを設定する。user agentとか。
 */
function get_context()
{
    //途中で変わる事はありえないので一度しか処理したくないのでstatic。
    static $context = null;

    if ($context) {
        //既にユーザーエージェントが設定済なら処理済ということで以下の処理は実行済という事で元に返る
        return $context;
    }

    switch (CARRIER_ID) {
        case "s":
            $user_agent = "Mozilla/5.0 (Linux; U; Android 2.2; ja-jp; SC-02B Build/FROYO) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1";
            break;
        case "a":
            $user_agent = "Mozilla/5.0 (iPhone; CPU iPhone OS 5_0 like Mac OS X) AppleWebKit/534.46 (KHTML, like Gecko) Version/5.1 Mobile/9A334 Safari/7534.48.3";
            break;
        case "i":
            $user_agent = "DoCoMo/2.0 SH04A";
            $added_header =
                "X_DCM_PAKEHO :1\n";
            break;
        case "e":
            $user_agent = "KDDI-KC4A UP.Browser/6.2_7.2.7.1.K.7.1.104 (GUI) MMP/2.0";
            break;
        case "y":
            $user_agent = "SoftBank/2.0/945SH/SHJ001[/Serial] Browser/NetFront/3.5 Profile/MIDP-2.0 Configuration/CLDC-1.1";
            //SBはこれも設定しないと駄目
            $added_header =
                "x-jphone-msname :945SH\n" .
                "x-jphone-uid: 2OTl2mqbiYIJ3FL";
            break;
        case "pc":
            $user_agent = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.11; rv:49.0) Gecko/20100101 Firefox/49.0";
            break;
        default:
            //どれにも該当しなければ、それそのものがユーザーエージェント。
            $user_agent = CARRIER_ID;
    }

    $header =
        "Accept-language: jp\n" .
        "User-Agent: {$user_agent}\n";
    if (isset($added_header)) {
        $header .= "{$added_header}";
    }
    $opts = array('http' =>
        array(
            'header' => "{$header}"
        )
    );


    // get_headerの動作制御用にデフォルトを変更する
    stream_context_get_default($opts);

    //コンテキスト生成
    $context = stream_context_create($opts);
    return $context;
}
