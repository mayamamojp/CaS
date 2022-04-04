<?php

namespace App\Http\Controllers;

use App\Libs\DataSendWrapper;
use App\Models\CTelToCust;
use App\Models\仕出し注文データ;
use App\Models\仕出し注文明細データ;
use App\Models\伝票ＮＯ管理マスタ;
use App\Models\売上データ明細;
use App\Models\得意先マスタ;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use DB;
use Exception;
use Illuminate\Support\Carbon;
use PDO;

class DAI08010Controller extends Controller
{

    /**
     * GetCustomerListForSelect
     */
    public function GetCustomerListForSelect($request)
    {
        $BushoCd = $request->bushoCd ?? $request->BushoCd;
        $WhereBusho = $BushoCd ? " AND TM.部署ＣＤ=$BushoCd" : "";

        $KeyWord = $request->KeyWord;
        $TelNo = !!$KeyWord ? str_replace('-', '', $KeyWord) : '';

        $WhereKeyWord = $KeyWord
            ? " AND (
                    TM.得意先名 LIKE '%$KeyWord%' OR
                    TM.備考１ LIKE '$KeyWord%' OR
                    TM.備考２ LIKE '$KeyWord%' OR
                    TM.備考３ LIKE '$KeyWord%' OR
                    TM.電話番号１ LIKE '$KeyWord%' OR
                    TM.電話番号１ LIKE '$TelNo%'
                )"
            : "";

        $pdo = DB::connection()->getPdo();

        if (is_numeric($KeyWord) && ctype_digit($KeyWord)) {
            $WhereCustomer = " AND TM.得意先ＣＤ=$KeyWord";

            //得意先ＣＤでの検索
            $ByCustomerSql = "
                SELECT
                    TM.得意先ＣＤ AS Cd,
                    TM.得意先名 AS CdNm,
                    TM.部署CD,
                    TM.得意先名カナ,
                    TM.得意先名略称,
                    TM.住所１,
                    TM.電話番号１,
                    TM.ＦＡＸ１,
                    TM.備考１,
                    TM.備考２,
                    TM.備考３,
                    TM.売掛現金区分,
                    TM.支払方法１,
                    TM.締日１,
                    BM.部署名
                FROM 得意先マスタ TM
                LEFT JOIN 部署マスタ BM
                    ON TM.部署CD = BM.部署CD
                WHERE 0=0
                $WhereBusho
                $WhereCustomer
            ";

            $stmt = $pdo->query($ByCustomerSql);
            $Result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($Result) == 1) {
                $pdo = null;
                return response()->json($Result);
            }
        }

        $sql = "
            SELECT
                TM.得意先ＣＤ AS Cd,
                TM.得意先名 AS CdNm,
                TM.部署CD,
                TM.得意先名カナ,
                TM.得意先名略称,
                TM.住所１,
                TM.電話番号１,
                TM.ＦＡＸ１,
                TM.備考１,
                TM.備考２,
                TM.備考３,
                TM.売掛現金区分,
                TM.支払方法１,
                TM.締日１,
                BM.部署名
            FROM 得意先マスタ TM
            LEFT OUTER JOIN 部署マスタ BM
                ON TM.部署CD = BM.部署CD
            WHERE 0=0
            $WhereBusho
            $WhereKeyWord
        ";

        $stmt = $pdo->query($sql);
        $DataList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;

        return response()->json(['Data' => $DataList]);
    }

    /**
     * GetOrderNoList
     */
    public function GetOrderNoList($request)
    {
        $BushoCd = $request->BushoCd;
        $DeliveryDate = $request->TargetDate;

        $sql= "
           SELECT
                仕出し注文データ.*
                ,仕出し注文データ.受注Ｎｏ AS Cd
                ,得意先マスタ.得意先名 AS CdNm
            FROM
                仕出し注文データ
                INNER JOIN 得意先マスタ
                    ON 得意先マスタ.得意先ＣＤ=仕出し注文データ.得意先CD
            WHERE
                仕出し注文データ.部署ＣＤ=$BushoCd
            AND 仕出し注文データ.配達日付='$DeliveryDate'
            ORDER BY
                仕出し注文データ.受注Ｎｏ
        ";


        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);
        $stmt = $pdo->query($sql);
        $DataList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo = null;

        return response()->json($DataList);
    }

    /**
     * GetCourseList
     */
    public function GetCourseList($request)
    {
        $BushoCd = $request->BushoCd;

        if (!isset($BushoCd)) return [];

        $sql = "
            SELECT
                *
                ,コースＣＤ AS Cd
                ,コース名 AS CdNm
            FROM
                コースマスタ
            WHERE
                部署ＣＤ=$BushoCd OR コースＣＤ = 0
            ORDER BY
                コースＣＤ
        ";

        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);
        $stmt = $pdo->query($sql);
        $DataList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo = null;

        return response()->json($DataList);
    }

    /**
     * Search
     */
    public function Search($request)
    {
        $Utilities = new UtilitiesController();

        return response()->json([
            [
                "CustomerInfo" => $this->GetCustomer($request),
                "OrderInfo" => $this->GetOrder($request),
                "MeisaiList" => $this->GetMeisaiList($request),
                "CheckSeikyu" => $this->CheckSeikyu($request),
                "CheckKaikei" => $this->CheckKaikei($request),
            ]
        ]);
    }

    /**
     * GetCustomer
     */
    public function GetCustomer($request)
    {
        $CustomerCd = $request->CustomerCd;

        $sql = "
            SELECT
                *
            FROM
                得意先マスタ
            WHERE
                得意先ＣＤ=$CustomerCd
        ";

        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);
        $stmt = $pdo->query($sql);
        $Result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo = null;

        return $Result;
    }

    /**
     * GetOrder
     */
    public function GetOrder($request)
    {
        $BushoCd = $request->BushoCd;
        $CustomerCd = $request->CustomerCd;
        $DeliveryDate = $request->DeliveryDate;

        $OrderNo = $request->OrderNo;
        $WhereOrderNo = !!$OrderNo ? "AND 仕出し注文データ.受注Ｎｏ=$OrderNo" : "";

        $sql = "
            SELECT TOP(1)
                仕出し注文データ.*
                ,担当者マスタ.担当者名 AS 修正担当者名
            FROM
                仕出し注文データ
                LEFT OUTER JOIN 担当者マスタ
                    ON 担当者マスタ.担当者ＣＤ=仕出し注文データ.修正担当者ＣＤ
            WHERE
                仕出し注文データ.部署ＣＤ=$BushoCd
            AND 仕出し注文データ.配達日付='$DeliveryDate'
            AND 仕出し注文データ.得意先ＣＤ=$CustomerCd
            $WhereOrderNo
            ORDER BY
                仕出し注文データ.受注Ｎｏ
        ";

        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);
        $stmt = $pdo->query($sql);
        $Result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo = null;

        return $Result;
    }

    /**
     * GetMeisaiList
     */
    public function GetMeisaiList($request)
    {
        $BushoCd = $request->BushoCd;
        $CustomerCd = $request->CustomerCd;
        $DeliveryDate = $request->DeliveryDate;

        $OrderNo = $request->OrderNo;
        $WhereOrderNo = !!$OrderNo ? "AND 受注Ｎｏ=$OrderNo" : "AND 受注Ｎｏ=受注Ｎｏ";

        $sql = "
            WITH 注文データ AS (
                SELECT TOP(1)
                    部署ＣＤ,
                    受注Ｎｏ,
					配達日付,
					得意先ＣＤ
                FROM
                    仕出し注文データ
                WHERE
                    部署ＣＤ=$BushoCd
                AND 配達日付='$DeliveryDate'
                AND 得意先ＣＤ=$CustomerCd
                $WhereOrderNo
                ORDER BY
                    受注Ｎｏ
            )
            SELECT
                仕出し注文明細データ.*
            FROM
                仕出し注文明細データ
                INNER JOIN 注文データ
					ON  注文データ.部署ＣＤ=仕出し注文明細データ.部署ＣＤ
                    AND 注文データ.受注Ｎｏ=仕出し注文明細データ.受注Ｎｏ
                    AND 注文データ.配達日付=仕出し注文明細データ.配達日付
            ORDER BY
                明細Ｎｏ
        ";

        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);
        $stmt = $pdo->query($sql);
        $DataList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;

        return $DataList;
    }

    /**
     * CheckSeikyu
     */
    public function CheckSeikyu($request)
    {
        $DeliveryDate = $request->DeliveryDate;
        $CustomerCd = $request->CustomerCd;

        $sql = "
            SELECT
            	IIF('$DeliveryDate' <= 請求日付, 1, 0) AS 請求済
            FROM
                請求データ
            WHERE 請求データ.請求先ＣＤ = $CustomerCd
            AND 請求日付 = (
                SELECT MAX(請求日付) FROM 請求データ DUM
                WHERE DUM.請求先ＣＤ = $CustomerCd )
        ";

        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);
        $stmt = $pdo->query($sql);
        $Result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo = null;

        return !!$Result ? $Result["請求済"] : 0;
    }

    /**
     * CheckKaikei
     */
    public function CheckKaikei($request)
    {
        $DeliveryDate = $request->DeliveryDate;
        $CustomerCd = $request->CustomerCd;

        $sql = "
            SELECT
                IIF('$DeliveryDate' <= EOMONTH(日付), 1, 0) AS 会計処理済
            FROM
                売掛データ
            WHERE 売掛データ.請求先ＣＤ = $CustomerCd
            AND 日付 = (
                SELECT MAX(日付) FROM 売掛データ DUM
                WHERE DUM.請求先ＣＤ = $CustomerCd )
        ";

        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);
        $stmt = $pdo->query($sql);
        $Result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo = null;

        return !!$Result ? $Result["会計処理済"] : 0;
    }

    /**
     * GetProductList
     */
    public function GetProductList($request)
    {
        $BushoCd = $request->BushoCd;

        $sql = "
            SELECT DISTINCT
                SHUBETSU.商品種類,
                SHUBETSU.商品種類名,
                SHUBETSU.商品ＣＤ,
                PRODUCT.商品名,
                PRODUCT.売価単価
            FROM
                商品種類マスタ SHUBETSU
                INNER JOIN 商品マスタ PRODUCT
                    ON  PRODUCT.商品ＣＤ=SHUBETSU.商品ＣＤ
            WHERE
                SHUBETSU.部署グループ = (
                    SELECT
                        MAX(サブ各種CD2)
                    FROM
                        各種テーブル
                    WHERE
                        各種CD=26
                    AND サブ各種CD1=$BushoCd
                )
            ORDER BY
                SHUBETSU.商品種類,
                SHUBETSU.商品ＣＤ
        ";

        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);
        $stmt = $pdo->query($sql);
        $DataList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;

        return response()->json($DataList);
    }

    /**
     * GetTaxList
     */
    public function GetTaxList($request) {
        $DeliveryDate = $request->DeliveryDate;

        $sql = "
            SELECT
                KAKU_NAIGAI.行NO AS 注文税区分
                ,SHOHIZEI.税区分
                ,SHOHIZEI.消費税名称
                ,SHOHIZEI.消費税率
                ,SHOHIZEI.内外区分
                ,SHOHIZEI.適用年月
                ,SHOHIZEI.現在使用FLG
                ,SHOHIZEI.修正担当者ＣＤ
                ,SHOHIZEI.修正日
            FROM
                消費税率マスタ SHOHIZEI
                INNER JOIN 各種テーブル KAKU_NAIGAI ON
                    KAKU_NAIGAI.各種CD = 20
                AND SHOHIZEI.内外区分 = KAKU_NAIGAI.サブ各種CD1
            WHERE
                SHOHIZEI.現在使用FLG = 1
            AND SHOHIZEI.適用年月 = (
                SELECT
                    MAX(DMY.適用年月)
                FROM
                    消費税率マスタ DMY
                WHERE
                    DMY.内外区分 = SHOHIZEI.内外区分
                    AND DMY.現在使用FLG = SHOHIZEI.現在使用FLG
                    AND DMY.適用年月 <= '$DeliveryDate'
                )
        ";

        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);
        $stmt = $pdo->query($sql);
        $DataList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;

        return response()->json($DataList);
    }

    /**
     * Save
     */
    public function Save($request)
    {
        $skip = [];

        DB::beginTransaction();

        $UriageDelCnt = 0;

        try {
            $params = $request->all();
            $date = Carbon::now()->format('Y-m-d H:i:s');

            //得意先マスタ
            $CustomerInfo = $params['CustomerInfo'];
            $TCode = 0;

            if (!!isset($CustomerInfo)) {
                $CustomerModel = new 得意先マスタ();
                $CustomerModel->fill($CustomerInfo);

                $CustomerData = collect($CustomerModel)->all();
                $TCode = $CustomerInfo['得意先ＣＤ'];

                if (!isset($TCode)) {
                    $GetTCodeSql = "
                        WITH TAISHO_NUM AS (
                            SELECT 299999 AS 得意先ＣＤ
                                UNION
                            SELECT
                                TOK.得意先ＣＤ
                            FROM
                                得意先マスタ TOK
                            WHERE
                                TOK.得意先ＣＤ BETWEEN 300000 AND 399999
                        )
                        SELECT TOP 1
                            TOK.得意先ＣＤ + 1 AS 得意先ＣＤ
                        FROM
                            TAISHO_NUM TOK
                            LEFT OUTER JOIN 得意先マスタ TOK_NEXT ON
                                (TOK.得意先ＣＤ + 1) = TOK_NEXT.得意先ＣＤ
                        WHERE
                            TOK_NEXT.得意先ＣＤ IS NULL
                        ORDER BY
                        TOK.得意先ＣＤ
                    ";

                    $TCode = DB::selectOne($GetTCodeSql)->得意先ＣＤ;
                    $request->CustomerCd = $TCode;
                }

                $CustomerData['得意先ＣＤ'] = $TCode;
                $CustomerData['請求先ＣＤ'] = $TCode;
                $CustomerData['受注得意先ＣＤ'] = $TCode;

                $CustomerData['修正日'] = $date;

                得意先マスタ::query()->updateOrInsert(
                    ['得意先ＣＤ' => $CustomerData['得意先ＣＤ']],
                    $CustomerData
                );

                //C_TelToCust
                $TelToCust = CTelToCust::query()
                    ->where('Tel_TelNo', str_replace("-","",$CustomerData['電話番号１']))
                    ->where('Tel_CustNo', $CustomerData['得意先ＣＤ'])
                    ->get();

                if (count($TelToCust) == 0) {
                    $TelDate = Carbon::parse($CustomerData['修正日'])->format('Y/m/d');
                    $TelToCust = CTelToCust::query()->insert(
                        [
                            "Tel_TelNo" => str_replace("-","",$CustomerData['電話番号１']),
                            "Tel_CustNo" => $CustomerData['得意先ＣＤ'],
                            "Tel_RepFlg" => 0,
                            "Tel_DelFlg" => 0,
                            "Tel_NewDate" => $TelDate,
                            "Tel_UpdDate" => $TelDate,
                        ]
                    );
                }
            }

            //仕出し注文データ
            $OrderInfo = $params['OrderInfo'];

            $JNo = 1;
            if (!!isset($OrderInfo)) {
                $OrderModel = new 仕出し注文データ();
                $OrderModel->fill($OrderInfo);

                $OrderData = collect($OrderModel)->all();

                $JNo = $OrderInfo['受注Ｎｏ'];
                if (!isset($JNo)) {
                    $fd = Carbon::parse($OrderInfo['配達日付'])->firstOfMonth()->toDateString();

                    $JNo = 伝票ＮＯ管理マスタ::query()
                        ->where('部署ＣＤ', $OrderInfo['部署ＣＤ'])
                        ->where('伝票種類', 1)
                        ->where('日付', $fd)
                        ->max('伝票ＮＯ') + 1;

                    $request->OrderNo = $JNo;

                    if ($JNo == 1) {
                        伝票ＮＯ管理マスタ::insert(
                            [
                                "部署ＣＤ" => $OrderInfo['部署ＣＤ'],
                                "伝票種類" => 1,
                                "日付" => $fd,
                                "伝票ＮＯ" => $JNo,
                                "修正担当者ＣＤ" => $OrderInfo['修正担当者ＣＤ'],
                                "修正日" => $date,
                            ]
                        );
                    } else {
                        伝票ＮＯ管理マスタ::query()
                            ->where('部署ＣＤ', $OrderInfo['部署ＣＤ'])
                            ->where('伝票種類', 1)
                            ->where('日付', $fd)
                            ->update(['伝票ＮＯ' => $JNo, '修正日' => $date]);
                    }
                }

                $OrderData['部署ＣＤ'] = $OrderInfo['部署ＣＤ'];
                $OrderData['受注Ｎｏ'] = $JNo;
                $OrderData['配達日付'] = $OrderInfo['配達日付'];
                $OrderData['得意先ＣＤ'] = $OrderInfo['得意先ＣＤ'] ?? $TCode;
                $OrderData['製造用特記'] = $OrderData['製造用特記'] ?? '';
                $OrderData['事務用特記'] = $OrderData['事務用特記'] ?? '';
                $OrderData['修正日'] = $date;

                仕出し注文データ::query()->updateOrInsert(
                    [
                        '部署ＣＤ' => $OrderData['部署ＣＤ'],
                        '受注Ｎｏ' => $OrderData['受注Ｎｏ'],
                        '配達日付' => $OrderData['配達日付'],
                    ],
                    $OrderData
                );
            }

            $DeleteList = $params['DeleteList'];
            foreach ($DeleteList as $rec) {
                仕出し注文明細データ::query()
                    ->where('部署ＣＤ', $rec['部署ＣＤ'])
                    ->where('受注Ｎｏ', $rec['受注Ｎｏ'])
                    ->where('配達日付', $rec['配達日付'])
                    ->where('明細Ｎｏ', $rec['明細Ｎｏ'])
                    ->delete();
            }

            $SaveList = $params['SaveList'];

            foreach ($SaveList as $rec) {
                $no = null;
                if (isset($rec['修正日']) && !!$rec['修正日']) {
                    $r = 仕出し注文明細データ::query()
                        ->where('部署ＣＤ', $rec['部署ＣＤ'])
                        ->where('受注Ｎｏ', $rec['受注Ｎｏ'])
                        ->where('配達日付', $rec['配達日付'])
                        ->where('明細Ｎｏ', $rec['明細Ｎｏ'])
                        ->get();

                    if (count($r) != 1) {
                        $skip = collect($skip)->push(["target" => $rec, "current" => null]);
                        continue;
                    } else if ($rec['修正日'] != $r[0]->修正日) {
                        $skip = collect($skip)->push(["target" => $rec, "current" => $r[0]]);
                        continue;
                    }

                    $no = $rec['明細Ｎｏ'];

                    仕出し注文明細データ::query()
                        ->where('部署ＣＤ', $rec['部署ＣＤ'])
                        ->where('受注Ｎｏ', $rec['受注Ｎｏ'])
                        ->where('配達日付', $rec['配達日付'])
                        ->where('明細Ｎｏ', $rec['明細Ｎｏ'])
                        ->delete();
                } else {
                    $no = 仕出し注文明細データ::query()
                        ->where('部署ＣＤ', $rec['部署ＣＤ'])
                        ->where('受注Ｎｏ', $JNo)
                        ->where('配達日付', $rec['配達日付'])
                        ->max('明細Ｎｏ') + 1;
                }

                $data = $rec;
                $data['受注Ｎｏ'] = $data['受注Ｎｏ'] ?? $JNo;
                $data['修正日'] = $date;
                $data['明細Ｎｏ'] = $no;
                $data['数量'] = $data['数量'] ?? 0;
                $data['金額'] = $data['金額'] ?? 0;
                $data['消費税'] = $data['消費税'] ?? 0;
                $data['備考'] = $data['備考'] ?? '';

                仕出し注文明細データ::insert($data);
            }

            //売上データ削除
            $UriageDelCnt = 売上データ明細::query()
                ->where('部署ＣＤ', $request->BushoCd)
                ->where('日付', $request->DeliveryDate)
                ->where('受注Ｎｏ', $request->OrderNo)
                ->delete();

            //モバイルsv更新用
            $Msql = "
                DELETE FROM SalesDetailsData
                WHERE department_code = '$request->BushoCd'
                AND date = '$request->DeliveryDate'
                AND order_no = '$request->OrderNo'
            ";

            if (count($skip) > 0) {
                DB::rollBack();
            } else {
                DB::commit();

                //モバイルsv更新
                $ds = new DataSendWrapper();
                $ds->Execute($Msql, true, null, null, null);

            }
        } catch (Exception $exception) {
            DB::rollBack();
            throw $exception;
        }

        return response()->json([
            'result' => true,
            "edited" => count($skip) > 0,
            "current" => $this->GetMeisaiList($request),
            "params" => ["CustomerCd" => $request->CustomerCd, "OrderNo" => $request->OrderNo],
            "isDeleteUriage" => $UriageDelCnt > 0,
        ]);
    }

    /**
     * Delete
     */
    public function Delete($request)
    {
        DB::beginTransaction();

        try {
            $BushoCd = $request->BushoCd;
            $CustomerCd = $request->CustomerCd;
            $DeliveryDate = $request->DeliveryDate;
            $OrderNo = $request->OrderNo;

            仕出し注文データ::query()
                ->where('部署ＣＤ', $BushoCd)
                ->where('受注Ｎｏ', $OrderNo)
                ->where('配達日付', $DeliveryDate)
                ->delete();

            仕出し注文明細データ::query()
                ->where('部署ＣＤ', $BushoCd)
                ->where('受注Ｎｏ', $OrderNo)
                ->where('配達日付', $DeliveryDate)
                ->delete();

            DB::commit();
        } catch (Exception $exception) {
            DB::rollBack();
            throw $exception;
        }

        return response()->json([
            'result' => true,
        ]);
    }
}
