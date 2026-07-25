<!doctype html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; margin-bottom: 24px; }
        th, td { border: 1px solid #9ca3af; padding: 6px 8px; mso-number-format: "\@"; }
        th { background: #e8f1ff; font-weight: 700; }
        .title { font-size: 18px; font-weight: 700; }
        .section { font-size: 14px; font-weight: 700; background: #f3f4f6; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td class="title" colspan="2">{{ $export['title'] }}</td>
        </tr>
        @foreach ($export['filters'] as $row)
            <tr>
                <th>{{ $row[0] }}</th>
                <td>{{ $row[1] }}</td>
            </tr>
        @endforeach
    </table>

    @foreach ($export['sections'] as $section)
        <table>
            <tr>
                <td class="section" colspan="{{ max(1, count($section['columns'])) }}">{{ $section['title'] }}</td>
            </tr>
            <tr>
                @foreach ($section['columns'] as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
            @forelse ($section['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(1, count($section['columns'])) }}">{{ __('app.no_data') }}</td>
                </tr>
            @endforelse
        </table>
    @endforeach
</body>
</html>
