<?php

public function bulkpayout(){
    header('Content-Type: application/json');

    if (!isset($_FILES['csv_file'])) {
        echo json_encode([
            'status'  => false,
            'message' => 'CSV file not received'
        ]);
        exit;
    }

    $fileTmpPath = $_FILES['csv_file']['tmp_name'];
    $fileError   = $_FILES['csv_file']['error'];

    if ($fileError !== 0) {
        echo json_encode([
            'status'  => false,
            'message' => 'File upload error'
        ]);
        exit;
    }

    $merchant_no = $_POST['mer_id'];

    $sql = "SELECT secretkey, sec_iv, end_point_url FROM tbl_user WHERE id = ?";
    $query = $this->db->query($sql, [$merchant_no]);
    $result = $query->row_array();

    if (empty($result)) {
        echo json_encode([
            'status'  => false,
            'message' => 'Merchant details not found'
        ]);
        exit;
    }

    $merchant_secret    = $result['secretkey'];
    $merchant_secret_iv = $result['sec_iv'];
    $end_point_url      = $result['end_point_url'];
    $utoken             = base64_encode($merchant_no . '::' . $merchant_secret);

    $rows = [];

    if (($handle = fopen($fileTmpPath, 'r')) !== false) {
        $header = fgetcsv($handle);

        if (empty($header)) {
            echo json_encode([
                'status'  => false,
                'message' => 'CSV header not found'
            ]);
            exit;
        }

        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
            if (empty(array_filter($data))) {
                continue;
            }

            if (count($header) !== count($data)) {
                continue;
            }

            $rows[] = array_combine($header, $data);
        }

        fclose($handle);
    } else {
        echo json_encode([
            'status'  => false,
            'message' => 'Unable to open CSV file'
        ]);
        exit;
    }

    if (empty($rows)) {
        echo json_encode([
            'status'  => false,
            'message' => 'No valid rows found in CSV'
        ]);
        exit;
    }

    $results = [];

    foreach ($rows as $index => $row) {

        $json_row = '[' . json_encode($row, JSON_UNESCAPED_SLASHES) . ']';

        if (empty($json_row)) {
            $results[] = [
                'row_number' => $index + 2,
                'status'     => false,
                'message'    => 'JSON row required'
            ];
            continue;
        }

        $encrypted = $this->encryptedValue1($json_row, $merchant_no, $merchant_secret, $merchant_secret_iv);

        $payout_data = [
            "end_point_url"   => $end_point_url,
            "enc_payout_json" => $encrypted
        ];

        $jdata = json_encode($payout_data, JSON_UNESCAPED_SLASHES);

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => 'https://your_domain',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $jdata,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-KEY: 12345',
                'User-token: ' . $utoken,
                'Authorization: Basic xyz'
            ],
        ]);

        $response = curl_exec($curl);
        $curl_error = curl_error($curl);
        $curl_errno = curl_errno($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($curl_errno) {
            $results[] = [
                'row_number' => $index + 2,
                'status'     => false,
                'message'    => 'Curl Error: ' . $curl_error
            ];
        } else {
            $results[] = [
                'row_number' => $index + 2,
                'status'     => true,
                'http_code'  => $http_code,
                'response'   => json_decode($response, true) ?: $response
            ];
        }
    }

    echo json_encode([
        'status'        => true,
        'message'       => 'Bulk payout processed',
        'total_rows'    => count($rows),
        'results'       => $results
    ]);
    exit;
}

?>