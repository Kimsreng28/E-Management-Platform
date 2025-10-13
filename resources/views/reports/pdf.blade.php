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
            font-family: "Inria Sans", Arial, sans-serif;
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

        .company-logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo {
            width: 60px;
            height: 60px;
            object-fit: contain;
            border: 2px solid #4f46e5;
            border-radius: 8px;
            padding: 5px;
            background: white;
        }

        .company-info h1 {
            font-family: "Inria Sans", Arial, sans-serif;
            font-size: 26px;
            margin: 0;
            color: #111827;
            font-weight: 700;
        }

        .company-info p {
            font-family: "Inria Sans", Arial, sans-serif;
            margin: 2px 0;
            font-size: 14px;
            color: #6b7280;
        }

        .report-date {
            font-family: "Inria Sans", Arial, sans-serif;
            text-align: right;
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 20px;
        }

        h2 {
            font-family: "Inria Sans", Arial, sans-serif;
            text-align: center;
            margin-bottom: 25px;
            font-size: 22px;
            color: #1f2937;
            padding: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        th {
            font-family: "Inria Sans", Arial, sans-serif;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: #000000;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
            padding: 15px 12px;
            border-right: 1px solid rgba(255, 255, 255, 0.2);
        }

        th:last-child {
            border-right: none;
        }

        td {
            font-family: "Inria Sans", Arial, sans-serif;
            padding: 12px 15px;
            border-bottom: 1px solid #e5e7eb;
            border-right: 1px solid #e5e7eb;
            text-align: left;
        }

        td:last-child {
            border-right: none;
        }

        tr:nth-child(even) {
            background-color: #f8fafc;
        }

        tr:hover {
            background-color: #eef2ff;
            transition: 0.2s;
        }

        .number-column {
            font-family: "Inria Sans", Arial, sans-serif;
            font-weight: 600;
            color: #4f46e5;
            text-align: center;
            background-color: #f8fafc;
        }

        .footer {
            font-family: "Inria Sans", Arial, sans-serif;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            padding-top: 12px;
            margin-top: 30px;
        }

        /* Zebra striping for better readability */
        tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        /* Ensure all text uses Inria Sans */
        * {
            font-family: "Inria Sans", Arial, sans-serif;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <div class="header">
        <div class="company-logo">
            <img src="{{ public_path('images/logo.png') }}" alt="Company Logo" class="logo">
            <div class="company-info">
                <h1>EMP Inc.</h1>
                <p>123 Main Street, Phnom Penh</p>
                <p>Email: info@emp-platform.com | Phone: +123 456 789</p>
            </div>
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
            @foreach ($data as $index => $row)
                <tr>
                    <td class="number-column">{{ $index + 1 }}</td>
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
