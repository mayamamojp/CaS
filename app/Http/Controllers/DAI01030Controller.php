<?php

namespace App\Http\Controllers;

use App\Libs\DataSendWrapper;
use App\Models\注文データ;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use DB;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PDO;

class DAI01030Controller extends Controller
{

    /**
     * GetProductList
     */
    public function GetProductList($request)
    {
        $CustomerCd = $request->CustomerCd;
        $DeliveryDate = $request->DeliveryDate;

        $sql = "
            SELECT
                MTT.商品ＣＤ AS Cd,
                PM.商品名 AS CdNm,
                MTT.得意先ＣＤ,
                MTT.商品ＣＤ,
                PM.商品名,
                PM.商品区分,
                MTT.単価
            FROM
                (
                    SELECT
                        *
                    FROM (
                        SELECT
                            *
                            , RANK() OVER(PARTITION BY 得意先ＣＤ, 商品ＣＤ ORDER BY 適用開始日 DESC) AS RNK
                        FROM
                            得意先単価マスタ新
                        WHERE
                            得意先ＣＤ=$CustomerCd
                        AND 適用開始日 <= '$DeliveryDate'
                    ) TT
                    WHERE
                        RNK = 1
                ) MTT
                LEFT OUTER JOIN 商品マスタ PM
                    ON	PM.商品ＣＤ=MTT.商品ＣＤ
        ";

        // $Result = collect(DB::select($sql))
        //     ->map(function ($product) {
        //         $vm = (object) $product;

        //         $vm->Cd = $product->商品ＣＤ;
        //         $vm->CdNm = $product->商品名;

        //         return $vm;
        //     })
        //     ->values();

        $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        $user = 'cas';
        $password = 'cas';

        $pdo = new PDO($dsn, $user, $password);

        $stmt = $pdo->query($sql);
        $Result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;

        return response()->json($Result);
    }

    /**
     * GetTodayOrder
     */
    public function GetTodayOrder($request)
    {
        $TantoCd = $request->TantoCd;
        $TantoCdLike = '1' . sprintf('%03d', $TantoCd) . '000%';

        $sql = "
            SELECT
                ROW_NUMBER() OVER (ORDER BY CONVERT(varchar, 修正日, 108) desc) AS no,
                部署ＣＤ,
                得意先CD,
                配送日,
                COUNT(得意先CD) AS 件数,
                CONVERT(varchar, 修正日, 108) 修正時間
            FROM
                注文データ
            WHERE
                CONVERT(varchar, 修正日, 112) = CONVERT(date, GETDATE())
                AND (修正担当者ＣＤ = $TantoCd OR 修正担当者ＣＤ like '$TantoCdLike')
				AND 注文区分=0
            GROUP BY
                部署ＣＤ,
                得意先CD,
                配送日,
                CONVERT(varchar, 修正日, 108)
            ORDER BY
                CONVERT(varchar, 修正日, 108) DESC
        ";

        $Result = DB::select($sql);

        return response()->json($Result);

    }

    /**
     * Search
     */
    public function Search($request)
    {
        return response()->json($this->GetOrderList($request));
    }

    function getUnixTimeMillSecond()
    {
        $arrTime = explode('.', microtime(true));
        return date('Y-m-d H:i:s', $arrTime[0]) . '.' . $arrTime[1];
    }

    /**
     * GetOrderList
     */
    public function GetOrderList($vm)
    {
        Log::debug('Start:' . $this->getUnixTimeMillSecond());
        $BushoCd = $vm->BushoCd;
        $CustomerCd = $vm->CustomerCd;
        $DeliveryDate = $vm->DeliveryDate;

        if (!isset($CustomerCd) || !is_numeric($CustomerCd) || !ctype_digit($CustomerCd)) {
            return [];
        }

        $pdo = DB::connection()->getPdo();

        $sql = "
            WITH 得意先単価 AS (
                SELECT
                    MTT.得意先ＣＤ,
                    MTT.商品ＣＤ,
                    PM.商品名,
                    PM.商品区分,
                    MTT.単価
                FROM
                    (
                        SELECT
                            *
                        FROM (
                            SELECT
                                *
                                , RANK() OVER(PARTITION BY 得意先ＣＤ, 商品ＣＤ ORDER BY 適用開始日 DESC) AS RNK
                            FROM
                                得意先単価マスタ新
                            WHERE
                                得意先ＣＤ=$CustomerCd
                            AND 適用開始日 <= '$DeliveryDate'
                        ) TT
                        WHERE
                            RNK = 1
                    ) MTT
                    LEFT OUTER JOIN 商品マスタ PM
                        ON	PM.商品ＣＤ=MTT.商品ＣＤ
            ),
            注文一覧 AS (
                SELECT
                    MTT.得意先ＣＤ,
                    MTT.商品ＣＤ,
                    IIF(CD.予備金額１ != 0, CD.予備金額１, MTT.単価) AS 単価,
                    MTT.商品名,
                    MTT.商品区分,
                    CD.注文区分,
                    CD.注文日付,
                    CD.注文時間,
                    CD.配送日,
                    CD.明細行Ｎｏ,
                    CD.入力区分,
                    CD.現金個数,
                    CD.現金金額,
                    CD.掛売個数,
                    CD.掛売金額,
                    CD.予備ＣＤ１,
                    CD.予備金額１,
                    CD.修正担当者ＣＤ,
                    CD.修正日
                FROM
                    得意先単価 MTT
                    LEFT OUTER JOIN 注文データ CD
                        ON  CD.得意先ＣＤ = MTT.得意先ＣＤ
                        AND CD.商品ＣＤ = MTT.商品ＣＤ
                        AND CD.配送日 = '$DeliveryDate'
            )
            SELECT
                得意先ＣＤ,
                MAX(注文日付) AS 注文日付,
                MAX(配送日) AS 配送日,
                MAX(IIF(注文区分=0, 注文時間, null)) AS 注文時間,
                MIN(注文区分) AS 注文区分,
                MAX(IIF(注文区分=0, 明細行Ｎｏ, 0)) AS 明細行Ｎｏ,
                商品ＣＤ,
                商品名,
                単価,
                商品区分,
                SUM(IIF(注文区分=1, 現金個数 + 掛売個数, 0)) AS 予定数,
                SUM(IIF(注文区分=0, 現金個数, 0)) AS 現金個数,
                SUM(IIF(注文区分=0, 現金金額, 0)) AS 現金金額,
                SUM(IIF(注文区分=0, 掛売個数, 0)) AS 掛売個数,
                SUM(IIF(注文区分=0, 掛売金額, 0)) AS 掛売金額,
                MAX(IIF(注文区分 IS NULL, 1, 0)) AS 全表示,
                MAX(IIF(注文区分=0, 修正日, null)) AS 修正日
            FROM
                注文一覧
            GROUP BY
                得意先ＣＤ,
                商品ＣＤ,
                単価,
                商品区分,
                商品名
            ORDER BY
                商品ＣＤ
        ";

        // $DataList = DB::select($sql);
        $stmt = $pdo->query($sql);
        $DataList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Log::debug('Search1:' . $this->getUnixTimeMillSecond());

        $HasShown = collect($DataList)->some(function ($item, $key) {
            return $item["全表示"] == 1;
        });

        if (!!$HasShown) {
            $sql = "
                WITH 得意先単価 AS (
                    SELECT
                        MTT.得意先ＣＤ,
                        MTT.商品ＣＤ,
                        PM.商品名,
                        PM.商品区分,
                        MTT.単価
                    FROM
                        (
                            SELECT
                                *
                            FROM (
                                SELECT
                                    *
                                    , RANK() OVER(PARTITION BY 得意先ＣＤ, 商品ＣＤ ORDER BY 適用開始日 DESC) AS RNK
                                FROM
                                    得意先単価マスタ新
                                WHERE
                                    得意先ＣＤ=$CustomerCd
                                AND 適用開始日 <= '$DeliveryDate'
                            ) TT
                            WHERE
                                RNK = 1
                        ) MTT
                        LEFT OUTER JOIN 商品マスタ PM
                            ON	PM.商品ＣＤ=MTT.商品ＣＤ
                )
                SELECT DISTINCT
                    注文データ.得意先ＣＤ,
                    注文データ.商品ＣＤ,
                    CASE WHEN 注文データ.予備金額１ IS NULL THEN 得意先単価.単価 ELSE 注文データ.予備金額１ END 単価,
                    商品マスタ.商品名,
                    0 タイトルフラグ
                FROM
                    [注文データ]
                    INNER JOIN
                        [商品マスタ] ON 商品マスタ.商品ＣＤ = 注文データ.商品ＣＤ
                    INNER JOIN
                        得意先単価 ON 注文データ.商品ＣＤ = 得意先単価.商品ＣＤ
                    AND
                        注文データ.得意先ＣＤ = 得意先単価.得意先ＣＤ
                WHERE 注文区分 = 0
                    AND 注文データ.得意先ＣＤ = $CustomerCd
                    AND 注文データ.注文区分 = 0
                    AND 注文データ.配送日 >= DATEADD(DAY, -8, '$DeliveryDate')
                    AND 注文データ.配送日 <=  '$DeliveryDate'
            ";

            // $products = DB::select($sql);
            $stmt = $pdo->query($sql);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $DataList = collect($DataList)->map(function ($item, $key) use ($products) {
                if (collect($products)->contains('商品ＣＤ', $item["商品ＣＤ"])) {
                    $item["全表示"] = '0';
                }
                return $item;
            })
            ->values()
            ;
            Log::debug('Search2:' . $this->getUnixTimeMillSecond());

        }

        $pdo = null;

        Log::debug('Result:' . $this->getUnixTimeMillSecond());
        return $DataList;
    }

    /**
     * GetBikou()
     */
    public function GetBikou($request)
    {
        $BushoCd = $request->BushoCd;
        $CustomerCd = $request->CustomerCd;
        $DeliveryDate = $request->DeliveryDate;

        if (!isset($CustomerCd) || !is_numeric($CustomerCd) || !ctype_digit($CustomerCd)) {
            return [];
        }

        $sql = "
            SELECT
				TK.得意先ＣＤ
                ,ISNULL(CD.特記_社内用, TK.備考１) AS 備考社内
                ,ISNULL(CD.特記_配送用, TK.備考２) AS 備考配送
                ,ISNULL(CD.特記_通知用, TK.備考３) AS 備考通知
				,CD.注文区分
            FROM
				得意先マスタ TK
				LEFT OUTER JOIN 注文データ CD
					ON  CD.得意先ＣＤ = TK.得意先ＣＤ
                    AND CD.配送日 = '$DeliveryDate'
            WHERE
                TK.得意先ＣＤ = $CustomerCd
            ORDER BY
                CD.注文区分 DESC
                ,CD.商品ＣＤ
        ";

        $pdo = DB::connection()->getPdo();

        $stmt = $pdo->query($sql);
        $Result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;

        return response()->json($Result);
    }

    /**
     * GetLastEdit()
     */
    public function GetLastEdit($request) {
        $BushoCd = $request->BushoCd;
        $CustomerCd = $request->CustomerCd;
        $DeliveryDate = $request->DeliveryDate;

        $sql = "
            SELECT TOP 1
                CD.修正担当者ＣＤ,
                TM.担当者名 AS 修正担当者名,
                CD.修正日
            FROM
                注文データ CD
                LEFT OUTER JOIN 担当者マスタ TM
                    ON  TM.担当者ＣＤ = CD.修正担当者ＣＤ
            WHERE
                CD.得意先ＣＤ = $CustomerCd
            AND CD.配送日 = '$DeliveryDate'
            ORDER BY
                修正日 DESC
        ";

        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->query($sql);
        $Result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo = null;

        return response()->json($Result);
    }

    /**
     * IsDeliveried()
     */
    public function IsDeliveried($request)
    {
        $CustomerCd = $request->CustomerCd;
        $DeliveryDate = $request->DeliveryDate;
        $CourseCd = $request->CourseCd;

        $WhereCourseCd = isset($CourseCd) ? "AND コースCD=$CourseCd" : "";

        $sql = "
            SELECT
                実績数
            FROM
                モバイル_販売入力
            WHERE
                得意先CD=$CustomerCd AND
                実績入力=1 AND
                日付='$DeliveryDate'
                $WhereCourseCd
        ";

        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->query($sql);
        $Result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;

        return response()->json(["IsDeliveried"=>count($Result) > 0]);
    }

    /**
     * GetCustomerInfo
     */
    public function GetCustomerInfo($request)
    {
        $CustomerCd = $request->CustomerCd ?? $request->customerCd;
        $DeliveryDate = $request->DeliveryDate;

        if (!isset($CustomerCd)) return;

        $sql = "
            SELECT
                M1.部署ＣＤ,
                MB.部署名,
                M1.得意先ＣＤ,
                M1.得意先名,
                M1.得意先名略称,
                M1.得意先名カナ,
                M1.売掛現金区分,
                M1.電話番号１,
                M1.備考１,
                M1.備考２,
                M1.備考３,
                MC.コースＣＤ,
                MC.コース名,
                MC.コース区分,
                MC.管理ＣＤ,
                MC.一時フラグ,
                MC.担当者ＣＤ,
                MT.担当者名
            FROM
                得意先マスタ M1
                LEFT OUTER JOIN 部署マスタ MB
                    ON MB.部署ＣＤ = M1.部署ＣＤ
                LEFT OUTER JOIN 祝日マスタ MH
                    ON  MH.対象日付 = '$DeliveryDate'
                    AND (対象部署ＣＤ IS NULL OR 対象部署ＣＤ LIKE '%' + STR(MB.部署ＣＤ) + '%')
                LEFT OUTER JOIN (
                    SELECT
                        CT.部署ＣＤ
                        ,CT.コースＣＤ
                        ,CT.管理ＣＤ
                        ,CTC.一時フラグ
                        ,CM.コース名
                        ,CM.コース区分
                        ,CM.担当者ＣＤ
                        ,CT.得意先ＣＤ
                    FROM
                        (
                            SELECT
                                部署ＣＤ, コースＣＤ, 0 AS 管理ＣＤ, ＳＥＱ, 得意先ＣＤ, 修正担当者ＣＤ, 修正日
                            FROM
                                コーステーブル
                            UNION ALL
                            SELECT
                                部署ＣＤ, コースＣＤ, 管理ＣＤ, ＳＥＱ, 得意先ＣＤ, 修正担当者ＣＤ, 修正日
                            FROM
                                コーステーブル一時
                        ) CT
                            INNER JOIN (
                                SELECT
                                    *
                                FROM (
                                    SELECT
                                        部署ＣＤ
                                        ,コースＣＤ
                                        ,管理ＣＤ
                                        ,一時フラグ
                                        ,RANK() OVER(PARTITION BY 部署ＣＤ, コースＣＤ ORDER BY 一時フラグ DESC) AS RNK
                                    FROM
                                        コーステーブル管理
                                    WHERE
                                        適用開始日 <= '$DeliveryDate' AND 適用終了日 >= '$DeliveryDate'
                                ) X
                                WHERE
                                    RNK = 1
                            ) CTC
                                ON  CTC.部署ＣＤ=CT.部署ＣＤ
                                AND CTC.コースＣＤ=CT.コースＣＤ
                                AND CTC.管理ＣＤ=CT.管理ＣＤ
                        LEFT JOIN コースマスタ CM
                            ON  CM.部署ＣＤ = CTC.部署ＣＤ
                            AND CM.コースＣＤ = CTC.コースＣＤ
                ) MC
                    ON  MC.部署ＣＤ = M1.部署CD
                    AND MC.得意先ＣＤ = M1.得意先ＣＤ
                    AND MC.コース区分 = IIF(MH.対象日付 IS NOT NULL, 4, CASE DATEPART(WEEKDAY, '$DeliveryDate') WHEN 1 THEN 3 WHEN 7 THEN 2 ELSE 1 END)
                LEFT OUTER JOIN 担当者マスタ MT
                    ON MT.担当者ＣＤ = MC.担当者ＣＤ
            WHERE
                M1.得意先CD = $CustomerCd
            AND (M1.受注得意先ＣＤ = 0 OR M1.受注得意先ＣＤ = M1.得意先ＣＤ)
        ";

        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->query($sql);
        $Result = $stmt->fetch(PDO::FETCH_ASSOC);

        $pdo = null;

        return response()->json($Result);
    }

    /**
     * GetCustomerInfoList
     */
    public function GetCustomerInfoList($request)
    {
        $Start = $request->Start;
        $Chunk = $request->Chunk;
        $End = $Start + $Chunk - 1;

        $DeliveryDate = $request->DeliveryDate;

        $BushoCd = $request->BushoCd;
        $WhereBusho = $BushoCd ? "AND M1.部署ＣＤ=$BushoCd" : "";

        $Keywords = $request->Keywords;
        $WhereKeyWord = "";
        if (!!$Keywords && !!count($Keywords)) {
            $Conditions = collect($Keywords)
                ->map(function ($Keyword) {
                    $Condition = "
                        (
                            M1.得意先名カナ LIKE '%$Keyword%'
                        )
                    ";

                    return $Condition;
                })
                ->join(' OR ');

            $WhereKeyWord = "AND ($Conditions)";
        }

        $Keyword = $request->Keyword;
        if (isset($Keyword) && (!!is_numeric($Keyword) || !!ctype_digit($Keyword))) {
            $WhereKeyWord = "AND M1.得意先ＣＤ=$Keyword";
        }

        $SearchTel = preg_replace('/-/', '', $request->SearchTel);
        $WhereTelNo = $SearchTel ? "AND ((REPLACE(M1.電話番号１, '-', '') = '$SearchTel') OR (REPLACE(M1.電話番号２, '-', '') = '$SearchTel'))" : "";

        $BaseSql = "
                SELECT
                    M1.得意先ＣＤ AS Cd,
                    M1.得意先名略称 AS CdNm,
                    M1.部署ＣＤ,
                    MB.部署名,
                    M1.得意先ＣＤ,
                    M1.得意先名,
                    M1.得意先名略称,
                    M1.得意先名カナ,
                    M1.売掛現金区分,
                    M1.電話番号１,
                    M1.備考１,
                    M1.備考２,
                    M1.備考３,
                    MC.コースＣＤ,
                    MC.コース名,
                    MC.コース区分,
                    MC.管理ＣＤ,
                    MC.一時フラグ,
                    MC.担当者ＣＤ,
                    MT.担当者名
					,ROW_NUMBER() OVER (ORDER BY M1.得意先ＣＤ) AS ROWNUM
                FROM
                    得意先マスタ M1
                    LEFT OUTER JOIN 部署マスタ MB
                        ON MB.部署ＣＤ = M1.部署ＣＤ
                    LEFT OUTER JOIN 祝日マスタ MH
                        ON  MH.対象日付 = '$DeliveryDate'
                        AND (対象部署ＣＤ IS NULL OR 対象部署ＣＤ LIKE '%' + STR(MB.部署ＣＤ) + '%')
                    LEFT OUTER JOIN (
						SELECT
							*
						FROM (
                            SELECT
                                CT.部署ＣＤ
                                ,CT.コースＣＤ
                                ,CT.管理ＣＤ
                                ,CTC.一時フラグ
                                ,CM.コース名
                                ,CM.コース区分
                                ,CM.担当者ＣＤ
                                ,CT.得意先ＣＤ
								,RANK() OVER(PARTITION BY CT.部署ＣＤ, CT.得意先ＣＤ ORDER BY CM.コース区分) AS RNK
                            FROM
                                (
                                    SELECT
                                        部署ＣＤ, コースＣＤ, 0 AS 管理ＣＤ, ＳＥＱ, 得意先ＣＤ, 修正担当者ＣＤ, 修正日
                                    FROM
                                        コーステーブル
                                    UNION ALL
                                    SELECT
                                        部署ＣＤ, コースＣＤ, 管理ＣＤ, ＳＥＱ, 得意先ＣＤ, 修正担当者ＣＤ, 修正日
                                    FROM
                                        コーステーブル一時
                                ) CT
                                    INNER JOIN (
                                        SELECT
                                            *
                                        FROM (
                                            SELECT
                                                部署ＣＤ
                                                ,コースＣＤ
                                                ,管理ＣＤ
                                                ,一時フラグ
                                                ,RANK() OVER(PARTITION BY 部署ＣＤ, コースＣＤ ORDER BY 一時フラグ DESC) AS RNK
                                            FROM
                                                コーステーブル管理
                                        ) X
                                        WHERE
                                            RNK = 1
                                    ) CTC
                                        ON  CTC.部署ＣＤ=CT.部署ＣＤ
                                        AND CTC.コースＣＤ=CT.コースＣＤ
                                        AND CTC.管理ＣＤ=CT.管理ＣＤ
                                LEFT JOIN コースマスタ CM
                                    ON  CM.部署ＣＤ = CTC.部署ＣＤ
                                    AND CM.コースＣＤ = CTC.コースＣＤ
							) CC
						WHERE
							RNK = 1
                    ) MC
                        ON  MC.部署ＣＤ = M1.部署CD
                        AND MC.得意先ＣＤ = M1.得意先ＣＤ
                    LEFT OUTER JOIN 担当者マスタ MT
                        ON MT.担当者ＣＤ = MC.担当者ＣＤ
                WHERE
                    (M1.受注得意先ＣＤ = 0 OR M1.受注得意先ＣＤ = M1.得意先ＣＤ)
                $WhereBusho
                $WhereTelNo
                $WhereKeyWord
        ";

        $SearchSql = "
            SELECT
                *
            FROM (
                $BaseSql
            ) AS T
            WHERE
                ROWNUM BETWEEN $Start AND $End
            ORDER BY
                ROWNUM
        ";

        $CountSql = "
            SELECT
                COUNT(T.得意先ＣＤ) AS CNT
            FROM (
                $BaseSql
            ) AS T
        ";

        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->query($SearchSql);
        $DataList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query($CountSql);
        $Count = $stmt->fetch()["CNT"];

        $pdo = null;

        return response()->json([
            [
                "End" => $End,
                "Count" => $Count,
                "Result" => $DataList,
            ]
        ]);
    }

    /**
     * GetCustomerAndCourseList
     */
    public function GetCustomerAndCourseList($request, $KeywordOnly = false)
    {
        $BushoCd = $request->BushoCd ?? $request->bushoCd;
        $CustomerCd = $request->CustomerCd ?? $request->customerCd;
        $TargetDate = $request->targetDate;
        $IsOyaOnly = $request->isOyaOnly ?? true;
        $Keyword = $request->keyword;

        $WhereOyaOnly = $IsOyaOnly ? " AND (M1.受注得意先ＣＤ = 0 OR M1.受注得意先ＣＤ = M1.得意先ＣＤ)" : "";

        $WhereCourseKbn = $TargetDate
            ? "AND MC.コース区分 =
                                            CASE
                                                WHEN (SELECT 対象日付 FROM 祝日マスタ WHERE 対象日付 = '$TargetDate') IS NOT NULL THEN 4
                                                ELSE
                                                    CASE DATEPART(WEEKDAY, '$TargetDate')
                                                        WHEN 1 THEN 3
                                                        WHEN 7 THEN 2
                                                        ELSE 1
                                                    END
                                            END"
            : "";

        $WhereKeyword = "";
        if (isset($Keyword)) {
            if (!is_numeric($Keyword) || !ctype_digit($Keyword)) {
                $WhereKeyword = "AND (
                    M1.得意先名 LIKE '$Keyword%' OR
                    M1.得意先名略称 LIKE '$Keyword%' OR
                    M1.得意先名カナ LIKE '$Keyword%' OR
                    M1.電話番号１ LIKE '$Keyword%'
                )";
            } else {
                $WhereKeyword = "AND M1.得意先CD LIKE '$Keyword%'";
            }

            if ($KeywordOnly) {
                $WhereCourseKbn = "";
            }
        }

        $sql = "";
        if (isset($CustomerCd) && (!!is_numeric($CustomerCd) || !!ctype_digit($CustomerCd))) {
            $WhereCustomer = $CustomerCd ? " AND M1.得意先CD = $CustomerCd" : "";
            $sql = "
                WITH 得意先_コース一覧 AS
                (
                    SELECT
                        M1.部署CD,
                        MB.部署名,
                        M1.得意先CD,
                        M1.得意先名,
                        M1.得意先名略称,
                        M1.得意先名カナ,
                        M1.売掛現金区分,
                        M1.電話番号１,
                        M1.備考１,
                        M1.備考２,
                        M1.備考３,
                        MC.担当者ＣＤ,
                        MT.担当者名,
                        MC.コース区分,
                        MC.コースCD,
                        MC.コース名,
                        RANK() OVER(PARTITION BY M1.部署CD, M1.得意先CD ORDER BY MC.コース区分) AS RNK
                    FROM
                        得意先マスタ M1
                        LEFT OUTER JOIN 部署マスタ MB
                            ON MB.部署CD = M1.部署CD
                        LEFT OUTER JOIN コーステーブル TC
                            ON M1.部署CD = TC.部署CD AND M1.得意先CD = TC.得意先CD
                        LEFT OUTER JOIN コースマスタ MC
                            ON TC.部署CD = MC.部署CD AND TC.コースCD = MC.コースCD
                        LEFT OUTER JOIN 担当者マスタ MT
                            ON MT.担当者ＣＤ = MC.担当者ＣＤ
                    WHERE
                        0=0
                        $WhereOyaOnly
                        $WhereCustomer
                        $WhereCourseKbn
                )
                SELECT
                    0,
                    得意先CD AS Cd,
                    得意先名,
                    部署CD,
                    部署名,
                    得意先CD,
                    得意先名,
                    得意先名略称 AS CdNm,
                    得意先名カナ,
                    売掛現金区分,
                    電話番号１,
                    備考１,
                    備考２,
                    備考３,
                    担当者ＣＤ,
                    担当者名,
                    コース区分,
                    コースCD,
                    コース名
                FROM
                    得意先_コース一覧
                WHERE
                    RNK = 1
            ";
        } else {
            $sql = "
                WITH 部署ソート AS (
                    SELECT
                        *
                        ,IIF(
                            部署CD=$BushoCd,
                            0,
                            CASE 部署CD
                                WHEN 101 THEN 1
                                WHEN 201 THEN 2
                                WHEN 301 THEN 3
                                WHEN 401 THEN 4
                                WHEN 901 THEN 5
                                WHEN 701 THEN 6
                                WHEN 601 THEN 7
                                WHEN 0 THEN 9999
                                ELSE 部署CD
                            END
                        ) AS ソート
                    FROM
                        部署マスタ
                ),
                得意先_コース一覧 AS
                (
                    SELECT
                        MB.ソート,
                        M1.部署CD,
                        MB.部署名,
                        M1.得意先CD,
                        M1.得意先名,
                        M1.得意先名略称,
                        M1.得意先名カナ,
                        M1.売掛現金区分,
                        M1.電話番号１,
                        M1.備考１,
                        M1.備考２,
                        M1.備考３,
                        MC.担当者ＣＤ,
                        MT.担当者名,
                        MC.コース区分,
                        MC.コースCD,
                        MC.コース名,
                        RANK() OVER(PARTITION BY M1.部署CD, M1.得意先CD ORDER BY MC.コース区分) AS RNK
                    FROM
                        得意先マスタ M1
                        LEFT OUTER JOIN 部署ソート MB
                            ON MB.部署CD = M1.部署CD
                        LEFT OUTER JOIN コーステーブル TC
                            ON M1.部署CD = TC.部署CD AND M1.得意先CD = TC.得意先CD
                        LEFT OUTER JOIN コースマスタ MC
                            ON TC.部署CD = MC.部署CD AND TC.コースCD = MC.コースCD
                        LEFT OUTER JOIN 担当者マスタ MT
                            ON MT.担当者ＣＤ = MC.担当者ＣＤ
                    WHERE
                        0=0
                        $WhereOyaOnly
                        $WhereCourseKbn
                        $WhereKeyword
                )
                SELECT
                    ソート,
                    得意先CD AS Cd,
                    得意先名,
                    部署CD,
                    部署名,
                    得意先CD,
                    得意先名,
                    得意先名略称 AS CdNm,
                    得意先名カナ,
                    売掛現金区分,
                    電話番号１,
                    備考１,
                    備考２,
                    備考３,
                    担当者ＣＤ,
                    担当者名,
                    コース区分,
                    コースCD,
                    コース名
                FROM
                    得意先_コース一覧
                WHERE
                    RNK = 1
                ORDER BY
                    ISNULL(ソート, 9999),
                    得意先ＣＤ
            ";
        }

        // $dsn = 'sqlsrv:server=127.0.0.1;database=cas';
        // $user = 'cas';
        // $password = 'cas';

        // $pdo = new PDO($dsn, $user, $password);
        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->query($sql);
        $DataList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo = null;

        if (count($DataList) == 0 && isset($Keyword)) {
            return $this->GetCustomerAndCourseList($request, true);
        }

        return response()->json($DataList);
    }

    /**
     * Save
     */
    public function Save($request)
    {
        Log::debug('Save Start:' . $this->getUnixTimeMillSecond());
        $skip = [];

        //モバイルsv更新用
        $MDeleteList = [];
        $MInsertList = [];

        DB::beginTransaction();

        try {
            $BushoCd = $request->BushoCd ?? $request->bushoCd;
            $DeliveryDate = $request->DeliveryDate;
            $CourseCd = $request->CourseCd;
            $CustomerCd = $request->CustomerCd;
            $CustomerNm = $request->CustomerNm;

            $params = $request->all();

            $DeleteList = $params['DeleteList'];
            foreach ($DeleteList as $rec) {
                注文データ::query()
                    ->where('注文区分', $rec['注文区分'])
                    ->where('注文日付', $rec['注文日付'])
                    ->where('部署ＣＤ', $rec['部署ＣＤ'])
                    ->where('得意先ＣＤ', $rec['得意先ＣＤ'])
                    ->where('配送日', $rec['配送日'])
                    ->where('明細行Ｎｏ', $rec['明細行Ｎｏ'])
                    ->delete();

                    //モバイルsv更新用
                    array_push($MDeleteList, $rec);
            }

            $SaveList = $params['SaveList'];

            $date = Carbon::now()->format('Y-m-d H:i:s');
            foreach ($SaveList as $rec) {
                $no = null;
                if (isset($rec['修正日']) && !!$rec['修正日']) {
                    $r = 注文データ::query()
                        ->where('注文区分', $rec['注文区分'])
                        ->where('注文日付', $rec['注文日付'])
                        ->where('部署ＣＤ', $rec['部署ＣＤ'])
                        ->where('得意先ＣＤ', $rec['得意先ＣＤ'])
                        ->where('配送日', $rec['配送日'])
                        ->where('明細行Ｎｏ', $rec['明細行Ｎｏ'])
                        ->get();

                    if (count($r) != 1) {
                        $skip = collect($skip)->push(["target" => $rec, "current" => null]);
                        continue;
                    } else if ($rec['修正日'] != $r[0]->修正日) {
                        $skip = collect($skip)->push(["target" => $rec, "current" => $r[0]]);
                        continue;
                    }

                    $no = $rec['明細行Ｎｏ'];

                    注文データ::query()
                        ->where('注文区分', $rec['注文区分'])
                        ->where('注文日付', $rec['注文日付'])
                        ->where('部署ＣＤ', $rec['部署ＣＤ'])
                        ->where('得意先ＣＤ', $rec['得意先ＣＤ'])
                        ->where('配送日', $rec['配送日'])
                        ->where('明細行Ｎｏ', $rec['明細行Ｎｏ'])
                        ->delete();

                    //モバイルsv更新用
                    array_push($MDeleteList, $rec);
                } else {
                    $no = 注文データ::query()
                        ->where('注文区分', $rec['注文区分'])
                        ->where('注文日付', $rec['注文日付'])
                        ->where('部署ＣＤ', $rec['部署ＣＤ'])
                        ->where('得意先ＣＤ', $rec['得意先ＣＤ'])
                        ->where('配送日', $rec['配送日'])
                        ->max('明細行Ｎｏ') + 1;
                }

                if (!!isset($rec['現金個数']) && !!isset($rec['掛売個数'])) {
                    $data = $rec;
                    $data['修正日'] = $date;
                    $data['明細行Ｎｏ'] = $no;
                    $data['備考１'] = $data['備考１'] ?? '';
                    $data['備考２'] = $data['備考２'] ?? '';
                    $data['備考３'] = $data['備考３'] ?? '';
                    $data['備考４'] = $data['備考４'] ?? '';
                    $data['備考５'] = $data['備考５'] ?? '';
                    $data['特記_社内用'] = $data['特記_社内用'] ?? '';
                    $data['特記_配送用'] = $data['特記_配送用'] ?? '';
                    $data['特記_通知用'] = $data['特記_通知用'] ?? '';

                    注文データ::insert($data);

                    //モバイルsv更新用
                    array_push($MInsertList, $data);

                }
            }

            if (count($skip) > 0) {
                DB::rollBack();
            } else {
                DB::commit();

                //モバイルsv更新
                $ds = new DataSendWrapper();
                $Message = null;
                if ($DeliveryDate == Carbon::now()->format('Ymd')) {
                    //当日注文の場合、通知
                    $Message = $request->Message;
                }
                $ds->UpdateOrderData($BushoCd, $DeliveryDate, $CustomerCd, $CourseCd, $Message);
            }
        } catch (Exception $exception) {
            DB::rollBack();
            throw $exception;
        }

        Log::debug('Save End:' . $this->getUnixTimeMillSecond());
        return response()->json([
            'result' => true,
            "edited" => count($skip) > 0,
            "current" => count($skip) > 0 ? $this->GetOrderList($request) : [],
        ]);
    }

    /**
     * Delete
     */
    public function Delete($request)
    {
        $skip = [];

        //モバイルsv更新用
        $MDeleteList = [];

        DB::beginTransaction();

        try {
            $params = $request->all();

            $DeleteList = $params['DeleteList'];

            foreach ($DeleteList as $rec) {
                if (isset($rec['修正日']) && !!$rec['修正日']) {
                    $r = 注文データ::query()
                        ->where('注文区分', $rec['注文区分'])
                        ->where('注文日付', $rec['注文日付'])
                        ->where('部署ＣＤ', $rec['部署ＣＤ'])
                        ->where('得意先ＣＤ', $rec['得意先ＣＤ'])
                        ->where('配送日', $rec['配送日'])
                        ->where('明細行Ｎｏ', $rec['明細行Ｎｏ'])
                        ->get();

                    if (count($r) != 1) {
                        $skip = collect($skip)->push(["target" => $rec, "current" => null]);
                        continue;
                    } else if ($rec['修正日'] != $r[0]->修正日) {
                        $skip = collect($skip)->push(["target" => $rec, "current" => $r[0]]);
                        continue;
                    }

                    注文データ::query()
                        ->where('注文区分', $rec['注文区分'])
                        ->where('注文日付', $rec['注文日付'])
                        ->where('部署ＣＤ', $rec['部署ＣＤ'])
                        ->where('得意先ＣＤ', $rec['得意先ＣＤ'])
                        ->where('配送日', $rec['配送日'])
                        ->where('明細行Ｎｏ', $rec['明細行Ｎｏ'])
                        ->delete();

                        //モバイルsv更新用
                    array_push($MDeleteList, $rec);
                }
            }

            if (count($skip) > 0) {
                DB::rollBack();
            } else {
                DB::commit();

                //モバイルsv更新
                foreach ($MDeleteList as $rec) {
                    $ds = new DataSendWrapper();
                    $ds->Delete('注文データ', $rec, false, $rec['部署ＣＤ'], null, null);
                }
            }
        } catch (Exception $exception) {
            DB::rollBack();
            throw $exception;
        }

        return response()->json([
            'result' => true,
            "edited" => count($skip) > 0 ? $this->GetOrderList($request) : [],
        ]);
    }
    /**
     * GetZandaka
     */
    public function GetZandaka($request)
    {
        $BushoCd = $request->BushoCd;
        $CustomerCd = $request->CustomerCd;

        $sql = "
SELECT
	請求先ＣＤ
FROM
	得意先マスタ
WHERE
	得意先ＣＤ = $CustomerCd
AND 部署ＣＤ = $BushoCd
        ";

        $SeikyuCd = DB::selectOne($sql);

        $sql = "
SELECT
	MAX(請求日付) 請求日付
FROM
	請求データ
WHERE
	請求先ＣＤ = $SeikyuCd->請求先ＣＤ
AND 部署ＣＤ = $BushoCd
        ";

        $SeikyuDate = DB::selectOne($sql);

        $sql = "
SELECT
	今回請求額
FROM
	請求データ
WHERE
	請求先ＣＤ = $SeikyuCd->請求先ＣＤ
AND CONVERT(varchar, 請求日付, 112) = FORMAT(CONVERT(date, '$SeikyuDate->請求日付'), 'yyyyMMdd')
AND 部署ＣＤ = $BushoCd
        ";

        $SeikyuVal = DB::selectOne($sql);


        $sql = "
SELECT
	SUM(掛売金額) 金額
FROM
	売上データ明細
WHERE
	CONVERT(varchar, 日付, 112) > FORMAT(CONVERT(date, '$SeikyuDate->請求日付'), 'yyyyMMdd')
AND 得意先ＣＤ = $CustomerCd
AND 部署ＣＤ = $BushoCd
        ";

        $UriageVal = DB::selectOne($sql);

        return response()->json([
            "Uriage" => !!$UriageVal ? $UriageVal->金額 : 0,
            "Zandaka" => !!$SeikyuVal ? $SeikyuVal->今回請求額 : 0,
        ]);
    }

    /**
     * SendPWA
     * (2020/10/01以降、当関数は使用しない。折を見て削除する。)
     */
    public function SendPWA($request)
    {
        $BushoCd = $request->BushoCd ?? $request->bushoCd;
        $DeliveryDate = $request->DeliveryDate;
        $CourseCd = $request->CourseCd;
        $CustomerCd = $request->CustomerCd;

        //モバイルsv更新
        $ds = new DataSendWrapper();
        $Message = null;
        if ($DeliveryDate == Carbon::now()->format('Y/m/d')) {
            //当日注文の場合、通知
            $Message = $request->Message;
        }
        $ds->UpdateOrderData($BushoCd, $DeliveryDate, $CustomerCd, $CourseCd, $Message);

        return;
    }
}
