<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Hanuman:wght@400;700&family=Inria+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap"
        rel="stylesheet">

    <style>
        body {
            font-family: 'Inria Sans', 'Hanuman', sans-serif;
            margin: 40px;
            background-color: #f9fafb;
            color: #333;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #4f46e5;
        }

        .company-info h1 {
            font-size: 26px;
            margin: 0;
            color: #111827;
            font-weight: 700;
        }

        .company-info p {
            margin: 2px 0;
            font-size: 14px;
            color: #6b7280;
        }

        .report-date {
            text-align: right;
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 20px;
        }

        h2 {
            text-align: center;
            margin-bottom: 25px;
            font-size: 22px;
            color: #1f2937;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            background: #fff;
            border-radius: 6px;
            overflow: hidden;
        }

        th {
            background-color: #4f46e5;
            color: #fff;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
        }

        th,
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        tr:hover {
            background-color: #eef2ff;
            transition: 0.2s;
        }

        .footer {
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            padding-top: 12px;
            margin-top: 30px;
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
