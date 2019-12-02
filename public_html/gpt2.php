<?php
    if (!isset($_GET['hello'])) {
        include('404.html');
        die();
    }

    if (isset($_GET['seed'])) {
        $seed = $_GET['seed'];
    } else {
        $seed = mt_rand();
    }

    if (isset($_POST['query'])) {
        $prompt = str_replace("'", "'\''", $_POST['query']);
        $command = "LANG=C.UTF-8 ../GPT-2 --length=500 --seed=$seed --prompt='$prompt'";
        $output = shell_exec($command);
        print $output;
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
        function doQuery() {
            if ($('#query').val() == '') {
                $('#query').val(' ');
            }
            $('#generate').prop('disabled', true);
            $('#result').html('Please wait...');
            $.post(window.location.href, '?hello&query=' + $('#query').val(), function (data, status) {
                if (data == '') {
                    $('#result').html('Please try again later when I am less busy...');
                    return;
                }
                $('#result').html('<b>' + $('#query').val() + '</b>' + data.replace(/\n/g, '<br />'));
                $('#generate').prop('disabled', false);
            });
        }
    </script>

    <style>
        html * {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 20px;
        }
    </style>
    </head>

<body>
    <div class="container">
        <div>
            <br>
            <b>Generate random text with OpenAI's 1.5B parameter GPT-2 model</b>
            <textarea id="query" value="" rows="3" style="width: 100%;"></textarea>
            <button id="generate" onclick="doQuery();">Generate</button>
        </div>
        <div>
            <scan id="result"></scan>
        </div>
    </div>
</body>
</html>
<?php
    }
?>