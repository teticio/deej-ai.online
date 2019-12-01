<?php
    if (!isset($_GET['hello'])) {
        include('404.html');
        die();
    }

    if (isset($_POST['query'])) {
        $prompt = str_replace("'", "'\''", mb_convert_encoding($_POST['query'], 'utf-8'));
        $command = "LANG=C.UTF-8 ../run_generation --model_type=gpt2 --model_name_or_path=gpt2-xl " .
            "--length=200 --seed=" . mt_rand() . " --prompt='" . $prompt . "'";
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
    <link href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet"
        integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">

    <script src="//ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js"
        integrity="sha384-xrRywqdh3PHs8keKZN+8zzc5TX0GRTLCcmivcbNJWm2rs5C8PRhcEn3czEjhAO9o"
        crossorigin="anonymous"></script>

    <script>
        function doQuery() {
            $('#generate').prop('disabled', true);
            $('#result').html('Please wait...');
            $.post(window.location.href, '?hello&query=' + $('#query').val(), function (data, status) {
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
            <h2>Generate random text with OpenAI's 1.5B parameter GPT-2 model</h2>
            <textarea id="query" value="" rows="5" style="width: 100%;"></textarea>
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