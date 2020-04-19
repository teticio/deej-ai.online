<?php
    $name = $email = "";
    $number = "1";

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $name = test_input($_POST["name"]);
        $email = test_input($_POST["email"]);
        $number = test_input($_POST["number"]);
        if (!empty($_POST["name"]) && !empty($_POST["email"])) {
            file_put_contents('../guests.csv', $name . ', ' . $email . ', ' . $number . PHP_EOL, FILE_APPEND | LOCK_EX);
            if ($number > 0) {
                header("Location: fiesta.php?gracias", true, 303);
            } else {
                header("Location: fiesta.php?vaya", true, 303);
            }
        }
    }

    function test_input($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        $data = str_replace(",", " ", $data);
        return $data;
    }
?>
<!doctype html>
<html lang="en">

<head>
    <title>RobExit</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--Bootstrap-->
    <link href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet"
        integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
    <link href="https://stackpath.bootstrapcdn.com/bootswatch/4.3.1/darkly/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-w+8Gqjk9Cuo6XH9HKHG5t5I1VR4YBNdPt/29vwgfZR485eoEJZ8rJRbm3TR32P6k" crossorigin="anonymous">

    <script src="//ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.bundle.min.js"
        integrity="sha384-xrRywqdh3PHs8keKZN+8zzc5TX0GRTLCcmivcbNJWm2rs5C8PRhcEn3czEjhAO9o"
        crossorigin="anonymous"></script>

    <style>
        html,
        body {
            height: 100%;
            font-family: 'Helvetica', 'Arial', sans-serif;
            background-color: #333;
            padding: 10px;
        }

        .flier {
            display: block;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }
    </style>
</head>

<body>
    <div class="text-center">
<?php if (isset($_GET["gracias"])) {?>
        ¡Muchas gracias! Si te surge un imprevsito y finalmente no puedes venir, mándame un correo a <a href="mailto:teticio@gmail.com">teticio@gmail.com</a>
<?php } elseif (isset($_GET["vaya"])) {?>
        ¡Qué pena! Mantente en contacto por favor. Mi correo electrónico es <a href="mailto:teticio@gmail.com">teticio@gmail.com</a>
<?php } else {?>
        Rellena el formulario abajo por favor
<?php }?>
    </div>
    <br>
    <a href="https://goo.gl/maps/Q2RNXSVpquFK8E2o8" target="_blank"><img src="fiesta2.jpg" class="flier"></a>
    <br>
<?php if (!isset($_GET["gracias"])) {?>
    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
        <div class="form-group row">
            <div class="col-md-3">
                <input class="form-control" name="name" value="<?php echo $name;?>" placeholder="Tú nombre">
<?php if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($_POST["name"])) {?>
                <small class="form-text text-danger">Introduce tu nombre por favor</small>
<?php }?>
            </div>
            <div class="col-md-4">
                <input type="email" class="form-control" value="<?php echo $email;?>" name="email" placeholder="Dirección de correo">
<?php if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($_POST["email"])) {?>
                <small class="form-text text-danger">Introduce tu email por favor</small>
<?php } else {?>
                <small class="form-text text-muted">Tu email nunca será compartido con nadie</small>
<?php }?>
            </div>
            <div class="col-md-3">
                <select class="form-control" name="number">
                    <option <?php if ($number == "0") echo "selected";?> value="0">No puedo asistir</option>
                    <option <?php if ($number == "1") echo "selected";?> value="1">Voy solo</option>
                    <option <?php if ($number == "2") echo "selected";?> value="2">Voy con un acompañante</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary float-right">Enviar</button>
            </div>
        </div>
    </form>
<?php }?>
</body>

</html>