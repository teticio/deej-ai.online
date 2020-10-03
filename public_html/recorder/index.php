<?php    
    require '../../vendor/autoload.php';

    $password = file_get_contents("../../password");
    $credentials = json_decode(file_get_contents("../../credentials"));
    $session = new SpotifyWebAPI\Session(
        $credentials->{'spotify_client_id'},
        $credentials->{'spotify_client_secret'},
        $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/recorder/'
    );

    $api = new SpotifyWebAPI\SpotifyWebAPI();
    $api->setSession($session);
    $api->setOptions(['auto_refresh' => true]);

    if (isset($_GET['code'])) {
        // get refresh token from callback url code
        $session->requestAccessToken($_GET['code']);
        header('Location: https://' . $_SERVER['SERVER_NAME'] . '/recorder/?' . http_build_query([
            'token' => $session->getRefreshToken(),
            trim($password) => ''
        ]));
        die();
    }

    if (!isset($_GET[trim($password)])) {
        include('404.html');
        die();
    }

    if (isset($_GET['token'])) {
        // get access token from refresh token
        try {
            $session->refreshAccessToken($_GET['token']);
        } catch (Exception $e) {
            $_GET['token'] = null;
        }
    }

    if (isset($_POST['action'])) {
        // do stuff
        switch ($_POST['action']) {
            case 'login':
                // get callback from spotify oauth
                $options = [
                    'scope' => [
                        'playlist-modify-public',
                        'user-read-currently-playing',
                    ],
                ];
                print $session->getAuthorizeUrl($options);
                die();
        }
    }
    
    use Aws\S3\S3Client;
    use GuzzleHttp\Promise;
    
    if (isset($_FILES['audio_data']) && isset($_GET['token'])) {
        $options = [
            'region'            => 'us-east-1',
            'version'           => '2006-03-01',
            'signature_version' => 'v4'
        ];
        
        $s3 = new S3Client($options);
        $size = $_FILES['audio_data']['size']; //the size in bytes
        $input = $_FILES['audio_data']['tmp_name']; //temporary name that PHP gave to the uploaded file
        $key = $_FILES['audio_data']['name'] . '_' . $api->me()->id . '_' . $session->getAccessToken() . '.wav';

        $result = $s3->putObject([
            'Bucket' => 'sam-deejai-audiobucket-irbmy1yr9mqj',
            'Key' => $key,
            'Body' => file_get_contents($input)
        ]);
        $timeout = 0;
            while ($timeout < 12 && !@$s3->doesObjectExist('deej-ai.online', $key)) {
                $timeout++;
                sleep(5);
            }
            if ($timeout < 12) {
                $result = $s3->getObject([
                    'Bucket' => 'deej-ai.online',
                    'Key' => $key
                ]);
                print($result->get('Body'));
                $result = $s3->deleteObject([
                    'Bucket' => 'deej-ai.online',
                    'Key' => $key
                ]);
                die();
            }
        die();
    }
?>

<!DOCTYPE html>
<html>
<head>
<title>Deej-A.I. - Automatically generate playlists based on how the music sounds</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

    <!--Favicons-->
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">

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
    <script src="https://cdn.rawgit.com/mattdiamond/Recorderjs/08e7abd9/dist/recorder.js"></script>

    <script>
        function login() {
            $.post(window.location.href, 'action=login', function (data, status) {
                window.location.href = data;
            });
        }

        //webkitURL is deprecated but nevertheless
        URL = window.URL || window.webkitURL;

        var gumStream; 						//stream from getUserMedia()
        var rec; 							//Recorder.js object
        var input; 							//MediaStreamAudioSourceNode we'll be recording

        // shim for AudioContext when it's not avb. 
        var AudioContext = window.AudioContext || window.webkitAudioContext;
        var audioContext //audio context to help us record

        var recordButton = document.getElementById("recordButton");
        var recording = false;
        var origBackground;

        function startStopRecording() {
            if (recording) {
                $('#recordButton').prop("onclick", null).off("click");
                $('#recordButton').css('background', origBackground);
                $('#recordButton').html('Please wait...');
                stopRecording();
                recording = false;
            } else {
                recording = true;
                origBackground = $('#recordButton').css('background');
                startRecording();
                $('#recordButton').css('background', '#dd0000');
                $('#recordButton').html('Stop recording');
            }
        }

        function startRecording() {
            console.log("recordButton clicked");

            /*
                Simple constraints object, for more advanced audio features see
                https://addpipe.com/blog/audio-constraints-getusermedia/
            */
            
            var constraints = { audio: true, video:false }

            /*
                Disable the record button until we get a success or fail from getUserMedia() 
            */

            /*
                We're using the standard promise based getUserMedia() 
                https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia
            */

            navigator.mediaDevices.getUserMedia(constraints).then(function(stream) {
                console.log("getUserMedia() success, stream created, initializing Recorder.js ...");

                /*
                    create an audio context after getUserMedia is called
                    sampleRate might change after getUserMedia is called, like it does on macOS when recording through AirPods
                    the sampleRate defaults to the one set in your OS for your playback device

                */
                audioContext = new AudioContext();

                /*  assign to gumStream for later use  */
                gumStream = stream;
                
                /* use the stream */
                input = audioContext.createMediaStreamSource(stream);

                /* 
                    Create the Recorder object and configure to record mono sound (1 channel)
                    Recording 2 channels  will double the file size
                */
                rec = new Recorder(input,{numChannels:1})

                //start the recording process
                rec.record()

                console.log("Recording started");

            }).catch(function(err) {
            });
        }

        function stopRecording() {
            console.log("stopButton clicked");

            if (rec) {
                //tell the recorder to stop the recording
                rec.stop();

                //stop microphone access
                gumStream.getAudioTracks()[0].stop();

                //create the wav blob and upload
                rec.exportWAV(function(blob){
                    var xhr=new XMLHttpRequest();
                    xhr.onload=function(e) {
                        if(this.readyState === 4) {
                            console.log("Server returned: ",e.target.responseText);
                        }
                        if (e.target.responseText) {
                            var url = 'https://open.spotify.com/playlist/' + e.target.responseText;

                            $('#recordButton').on('click', (function() {
                                window.location.href=url;
                            }));
                            $('#recordButton').html('Open Spotify');
                        }
                    };
                    var fd=new FormData();
                    fd.append("audio_data",blob, new Date().toISOString());
                    xhr.open("POST",window.location.href,true);
                    xhr.send(fd);
                });
            }
        }
    </script>

    <style>
        .container {
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
    </style>
</head>

<body>
    <div class="container">
        <div style="margin: auto auto;">
<?php if (!isset($_GET['token'])) { ?>
            <div style="color: inherit" class="btn btn-primary btn-lg text-center" onclick="login();">Login to Spotify</div>
<?php } else {?>
            <div style="color: inherit" class="btn btn-primary btn-lg text-center" id="recordButton" onclick="startStopRecording();">Start recording</div>
<?php }?>
        </div>
    </div>
</body>
</html>