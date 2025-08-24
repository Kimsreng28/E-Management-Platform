<!DOCTYPE html>
<html>

<head>
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
        }

        .header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }

        .logo {
            width: 80px;
            height: 80px;
            margin-right: 20px;
        }

        .company-info {
            font-size: 16px;
        }

        .company-info h1 {
            margin: 0;
            font-size: 22px;
        }

        .company-info p {
            margin: 2px 0;
            font-size: 14px;
        }

        .report-date {
            text-align: right;
            font-size: 12px;
            color: #555;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
        }

        table,
        th,
        td {
            border: 1px solid #333;
        }

        th,
        td {
            padding: 10px;
            text-align: left;
        }

        th {
            background-color: #f5f5f5;
        }

        tr:nth-child(even) {
            background-color: #fafafa;
        }

        h2 {
            text-align: center;
            margin-bottom: 20px;
        }

        .footer {
            text-align: center;
            font-size: 12px;
            color: #777;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <div class="header">
        <div class="company-info">
            <h1>EMP Inc.</h1>
            <p>123 Main Street, City, Country</p>
            <p>Email: info@company.com | Phone: +123 456 789</p>
        </div>
    </div>

    <!-- Report title and date -->
    <div class="report-date">
        <strong>Report Date:</strong> {{ now()->format('d M Y H:i') }}
    </div>

    <h2>{{ $title }}</h2>

    <!-- Report table -->
    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($data as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Footer -->
    <div class="footer">
        © {{ date('Y') }} EMP Inc. All rights reserved.
    </div>
</body>

</html>
