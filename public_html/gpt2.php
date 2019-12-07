<?php
    $password = file_get_contents("../password");
    if (!isset($_GET[trim($password)])) {
        include('404.html');
        die();
    }

    if (isset($_GET['seed'])) {
        $seed = $_GET['seed'];
    } else {
        $seed = mt_rand();
    }

    if (isset($_GET['temperature'])) {
        $temperature = $_GET['temperature'];
    } else {
        $temperature = 1.0;
    }

    // directory to store active sessions
    $ids_dir = '../gpt2';

    // garbage collection
    $dir = new DirectoryIterator(__DIR__ . "/$ids_dir");
    foreach ($dir as $fileinfo) {
        if (!$fileinfo->isDot() && time() - filemtime($fileinfo->getPathname()) > strtotime('1 day', 0)) {
            unlink($fileinfo->getPathname());
        }
    }

    if (isset($_POST['query'])) {
        $id = uniqid();
        @file_put_contents("$ids_dir/$id.seed", $seed);
        $prompt = str_replace("'", "'\''", $_POST['query']);
        $command = "LANG=C.UTF-8 ../GPT2 $id --length=500 --seed=$seed --prompt='$prompt' --temperature='$temperature' > $ids_dir/$id &";
        shell_exec($command);
        print $id;

    } elseif (isset($_POST['result'])) {
        $text = file_get_contents("$ids_dir/{$_POST['result']}");
        $done = !file_exists(__DIR__ . "/$ids_dir/{$_POST['result']}.lock");
        $seed = file_get_contents("$ids_dir/{$_POST['result']}.seed");
        print json_encode([
            'text' => $text,
            'done' => $done,
            'seed' => $seed
        ]);
        if ($done) {
            unlink("$ids_dir/{$_POST['result']}");
            unlink("$ids_dir/{$_POST['result']}.seed");
        }
    } else {

?>
<!doctype html>
<html lang="en">

<head>
    <title>GPT-2</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--Bootstrap-->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">

    <!--JQuery-->
    <script src="//ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>

    <script>
        var entityMap = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
            '/': '&#x2F;',
            '`': '&#x60;',
            '=': '&#x3D;'
        };

        function escapeHtml(string) {
            return String(string).replace(/[&<>"'`=\/]/g, function (s) {
                return entityMap[s];
            }).replace(/ /gm, '&nbsp;<wbr>').replace(/\n/g, '<br />');
        }

        function doQuery() {
            if ($('#query').val() == '') {
                $('#query').val(' ');
            }
            $('#seed').empty();
            $('#generate').prop('disabled', true).html('Please wait...');
            $('#result').html('<b>' + escapeHtml($('#query').val()) + '</b>');
            $.post(window.location.href,
                   'query=' + encodeURIComponent($('#query').val()),
                   function (data, status) {
                var id = data;
                var text = '';
                var tail = setInterval(function () {
                    $.post(window.location.href, 'result=' + id, function (data, status) {
                        result = JSON.parse(data);
                        if (result['text'] == 'Please try again when I am less busy...\n') {
                            $('#result').html(result['text']);
                        } else if (result['text'] != '') {
                            var chunk = result['text'].substr(text.length);
                            text += chunk;
                            $('#result').append('<span style="animation: fadein 1s;">' +
                                escapeHtml(chunk) + '</span>');
                            $('#seed').html('&nbsp;(Seed = ' + result['seed'] + ')');
                        }
                        if (result['done']) {
                            clearInterval(tail);
                            $('#generate').prop('disabled', false).html('Generate');
                        }
                    });
                }, 100);
            });
        }
    </script>

    <style>
        html * {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 20px;
        }

        @keyframes fadein {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
    </style>
</head>

<body>
    <div class="container">
        <div>
            <br>
            <b>Generate random text with OpenAI's 1.5B parameter GPT-2 model</b>
            <textarea id="query" placeholder="Prompt" value="" rows="3" style="width: 100%;"></textarea>
            <button id="generate" onclick="doQuery();">Generate</button><scan id="seed" style="font-size: 15px;"></scan>
        </div>
        <div id="result">
        </div>
    </div>
</body>
</html>
<?php
    }
?>