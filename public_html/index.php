<?php
    // playlist name and link
    // similar
    // demo
    // handle errors
    // check origin of request coming from deej-ai.online
    // log
    // rotate screen in app?
    // cookies
    // 404
    // https

    require '../vendor/autoload.php';

    $session = new SpotifyWebAPI\Session(
        '1a7897e3c69d4684aa4d8e90d5911594',
        'c60a83ca283449afb39e63841a1af60d',
        $_SERVER['SCRIPT_URI']
    );

    $api = new SpotifyWebAPI\SpotifyWebAPI();
    $api->setSession($session);
    $api->setOptions([
        'auto_refresh' => true,
    ]);

    // spotify server url
    $spotify_server = 'http://localhost:5123/spotify_server';

    // directory to store active ids
    $ids_dir = '../ids';

    // get playlist from spotify server
    function getPlaylist() {
        global $api;
        global $session;
        global $spotify_server;
        global $ids_dir;

        $postdata = [
            'tracks' => $_POST['tracks'],
            'replace' => $_POST['replace'],
            'size' => $_POST['size'],
            'creativity' => $_POST['creativity'],
            'noise' => $_POST['noise']
        ];

        if (($token = $session->getAccessToken()) != '') {
            $postdata['access_token'] = $token;
            $postdata['user_name'] = $api->me()['id'];
            $postdata['playlist'] = $_POST['playlist'];
        }

        // create file that gets deleted if user goes away
        if (isset($client_id)) {
            $file = fopen($ids_dir .'/' . $client_id, 'w');
            fclose($file);
            $postdata['client_id'] = $client_id;
        }
        $payload = json_encode($postdata); 

        $ch = curl_init($spotify_server);
        // stream results
        ob_implicit_flush(true);
        ob_end_flush();

        $callback = function ($ch, $str) {
            $tracks = explode(' ', $str);
            foreach ($tracks as $track) {
                if ($track == '') { 
                    continue;
                }
                if (substr($track, 0, 12) != 'playlist_id:') {
                    print '<iframe src="https://open.spotify.com/embed/track/' . $track . '" width="100%" height="80" frameborder="0" allowtransparency="true" allow="encrypted-media"></iframe>';
                }
            }
            return strlen($str);
        };

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ],
            CURLOPT_WRITEFUNCTION => $callback
        ]);
    
        curl_exec($ch);
        curl_close($ch);
    }

    // get search results from spotify server
    function searchTracks() {
        global $spotify_server;
        global $ids_dir;

        if (!isset($_POST['url'])) {
            // search for string
            $postdata = [
                'search_string' => $_POST['search_string']
            ];
        } else {
            // search for similar sounding tracks
            $postdata = [
                'track_url' => $_POST['url']
            ];
        }
        $payload = json_encode($postdata); 

        $ch = curl_init($spotify_server);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ]
        ]);
    
        print curl_exec($ch);
        curl_close($ch);
    }

    if (isset($_GET['code'])) {
        // get refresh token from callback url code
        $session->requestAccessToken($_GET['code']);
        header('Location: http://' . $_SERVER['SERVER_NAME'] . '?' . http_build_query([
            'token' => $session->getRefreshToken()
        ]));
        die();
    }

    if (isset($_GET['token'])) {
        // get access token from refresh token
        $session->refreshAccessToken($_GET['token']);
    }

    if (!isset($_POST['action'])) {
        // load page

?>
<!doctype html>
<html lang='en'>

<head>
    <script src='//ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js'></script>
</head>

<body>
<!doctype html>
<html lang="en">
<html>

<head>
    <title>Deej-A.I. - Automatically generate playlists based on how the music sounds</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

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

    <!--on document ready-->
    <script>
        const getUniqueID = () => {
            const s4 = () => Math.floor((1 + Math.random()) * 0x10000).toString(16).substring(1);
            return s4() + s4() + '-' + s4();
        };
        var id = null;

        $(document).ready(function () {
            $(window).on('unload', function () {
                if (id) {
                    $.post(window.location.href, 'action=bye&id=' + id);
                }
            });

            $('#creativity_slider').on('input', function () {
                $('#creativity').html(this.value);
            });

            $('#noise_slider').on('input', function () {
                $('#noise').html(this.value);
            });

            $('#enable_creativity').on('input', function () {
                $('#creativity_slider').prop('disabled', !this.checked);
            });

            $('#enable_noise').on('input', function () {
                $('#noise_slider').prop('disabled', !this.checked);
            });

            $('#size_input').on('change', function () {
                this.value = Math.max(1, Math.min(this.value, 100));
            });
            
            // Bootstrap tooltips
            $(document).ready(function () {
                $('[data-toggle="tooltip"]').tooltip();
            });
        });

        function login() {
            $.post(window.location.href, 'action=login', function (data, status) {
                window.location.href = data;
            });
        }

        function searchTracks() {
            $('#similar_to').attr("disabled", true).tooltip("hide")
            $('#search_input').tooltip("hide")
            if (search_input.value == '') {
                return;
            }
            $.post(window.location.href, jQuery.param({
                'action': 'search',
                'search_string': $('#search_input').val()
            }), function (data, status) {
                // reactivate button and remove spinner
                $('#similar_to').html('Similar').attr("disabled", false);
                results = JSON.parse(data);
                $('#search_results').empty();
                results.forEach(function (item) {
                    $('#search_results').append(new Option(item['track'], item['id']));
                })
                $('#search_results option:eq(0)').text((results.length > 0)? 'Select to add to playlist' : '');
                if (results.length == 1) {
                    $('#num_found').html('1 search result');
                } else if (results.length >= 100) {
                    $('#num_found').html('First 100 search results');
                } else {
                    $('#num_found').html(results.length + ' search results');
                }
            });
        }

        // add tracks to tracklist
        function updateAddDropdownText() {
            var n = $('#tracks > option').length;
            $('#tracks option:eq(0)').text((n > 1)? 'Select to remove from playlist' : '');
            if (n == 1) {
                $('#num_added').html('tracks');
            } else if (n == 2) {
                $('#num_added').html('<span class="text-info"><b>1</b></span> track');
            } else {
                $('#num_added').html('<span class="text-info"><b>' + (n - 1) + '</b></span> tracks');
            }
        }
            
        function addTracks() {
            if ($('#tracks > option').length < 6 && $('#search_results').prop('selectedIndex') != 0) {
                $('#tracks').append(new Option($('#search_results option:selected').text(), $('#search_results').val()));
                $('#search_results option:selected').prop('selected', false);
                $('#search_results eq:0').prop('selected', true);
                updateAddDropdownText();
            }
        }

        // remove tracks from tracklist
        function removeTracks() {
            $('#tracks option:selected').remove();
            $('#tracks eq:0').prop('selected', true);
            updateAddDropdownText();
        }

        function generatePlaylist() {
             // disable button and add spinner
            $('#generate').html('<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>Go!').attr("disabled", true).tooltip("hide");
            if (id) {
                $.post(window.location.href, 'action=bye&id=' + id);
            }
            id = getUniqueID();
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            var done = 0;
            $('#playlist').html();
            xhr.onprogress = function () {
                chunk = xhr.responseText.substring(done);
                $('#playlist').append(chunk);
                done = done + chunk.length;
            }
            tracks = [];
            $("#tracks option").each(function () {
                tracks.push($(this).val());
            });
            tracks.shift();
            xhr.send(jQuery.param({
                'action': 'go',
                'id': id,
                'playlist': $('#playlist_input').val(),
                'tracks': tracks,
                'replace': $('#replace').is(':checked')? '1': '0',
                'size': $('#size_input').val(),
                'creativity': $('#creativity_slider').val(),
                'noise': $('#noise_slider').val()
            }));
        }
    </script>

    <!--Banner style-->
    <style>
        .banner {
            background-image: url("records.jpg");
            background-size: cover;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-position: center center;
        }
    </style>

    <!--Link style-->
    <style>
        a,
        a:hover,
        a:focus,
        a:active,
        a:visited {
            color: #1ed761;
        }
    </style>

    <!--Footer style-->
    <style>
        .footer {
            position: fixed;
            left: 0;
            bottom: 0;
            width: 100%;
            text-align: center;
            opacity: 0.7
        }
    </style>

    <!--Social media button style-->
    <style>
        .fa {
            padding: 5px;
            font-size: 25px;
            width: 30px;
            text-align: center;
            text-decoration: none;
            margin: 5px 2px;
            border-radius: 50%;
        }

        .fa:hover {
            opacity: 0.7;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <!--Header-->
    <div class="container">
        <div class="form-group">
            <div class="row align-items-center banner">
                <div class="col">
                    <div class="col-sm-auto">
                        <h2><a href="" style="text-decoration: none; color: inherit">Deej-A.I.</a></h2>
                    </div>
                    <div class="col-sm-auto">
                        <h6>by <a href="https://www.linkedin.com/in/attentioncoach/" target="_blank">Robert Smith</a>
                        </h6>
                    </div>
                </div>
                <div class="col">
<?php if (!isset($_GET['token'])) { ?>
                    <div class="col-sm-auto" id="login">
                        <div class="float-right">
                            <div style="color: inherit" class="btn btn-primary" data-toggle="tooltip" onclick="login();"
                                title="Allows you to save your playlists in Spotify">Connect to Spotify (optional)
                            </div>
                        </div>
                    </div>
<?php } else { ?>
                    <div class="col-sm-auto" id="loggedin">
                        <div class="float-right">
                            <div class="row align-items-center">
                                <div class="col-sm-auto">
                                    <input type="text" class="form-control" placeholder="Create playlist"
                                        value="Deej-A.I." size="11" input-type="search" id="playlist_input">
                                </div>
                                <div class="col-sm-auto">
                                    <div class="custom-control custom-checkbox" data-toggle="tooltip"
                                        title="Replace or extend existing playlist?">
                                        <input type="checkbox" class="custom-control-input" checked id="replace_input">
                                        <label class="custom-control-label" for="replace_input">Replace?</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
<?php } ?>
                </div>
            </div>
        </div>

        <!--Form-->
        <hr color="white">
        <div class="form-group">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <input type="text" class="form-control" placeholder="Search..." onchange="searchTracks()" size="10"
                        id="search_input" data-toggle="tooltip" title="Search the database of more than 320,000 tracks">
                </div>
                <div class="col-md-6">
                    <div class="row align-items-center">
<?php if (isset($_GET['token'])) { ?>
                        <div class="col-auto" id="similar">
                            <button class="btn btn-primary" type="button" onclick="getSimilar()" id="similar_to"
                                data-toggle="tooltip"
                                title="Find similar sounding tracks to one currently playing on Spotify.">Similar</button>
                        </div>
                        <div class="col-auto" id="similar2">
                            <h5><span id="current_spotify_track"></span></h5>
                        </div>
<?php } ?>
                    </div>
                </div>
            </div>
            <br>
            <div class="row align-items-center">
                <div class="col-sm-6">
                    <label for="search_results"><span id="num_found">Search results</span></label>
                    <select class="form-control" size="4" style="overflow-x: scroll" oninput="addTracks()"
                        id="search_results">
                        <option value="" disabled selected></option>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label for="tracks">Added <span id="num_added">tracks</span></label>
                    <select class="form-control" size="4" style="overflow-x: scroll" oninput="removeTracks()"
                        id="tracks">
                        <option value="" disabled selected></option>
                    </select>
                </div>
            </div>
            <div class="row align-items-center">
                <div class="col-md-1">
                    <div class="text-center">
                        <br>
                        <button class="btn btn-primary" type="button" onclick="generatePlaylist()" id="generate"
                            data-toggle="tooltip"
                            title="If two or more tracks are selected, a playlist will be generated that joins the dots between them. If no tracks are selected, a playlist based on a random track will be generated.">Go!</button>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" checked id="enable_creativity">
                        <label class="custom-control-label" for="enable_creativity">Creativity = <span
                                id="creativity">0.5</span></label>
                    </div>
                    <input type="range" class="custom-range" min="0" max="1" step="0.01" value="0.5"
                        id="creativity_slider" data-toggle="tooltip"
                        title="A value of 0 will select tracks based on how likely they are to appear in a user's playlist. A value of 1 will select tracks based purely on how they sound. For very popular tracks, a higher number is recommended while for less distinctive music, it is better to choose a lower number.">
                </div>
                <div class="col-md-5">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="enable_noise">
                        <label class="custom-control-label" for="enable_noise">Noise = <span id="noise">0</span></label>
                    </div>
                    <input type="range" class="custom-range" min="0" max="1" step="0.01" value="0" disabled
                        id="noise_slider" data-toggle="tooltip" title="Controls the amount of randomness to apply">
                </div>
                <div class="col-md-1">
                    <label for="size_input">Size</label>
                    <input type="number" class="form-control" value="10" min="1" max="100" id="size_input">
                </div>
            </div>
        </div>

        <!--Tracklist-->
        <hr color="white">
        <p>
            <div id="playlist">
                <h5>Discover new music using Artificial Intelligence to automatically generate playlists based on how
                    the music sounds. Create your own musical journey that smoothly "<i>joins the dots</i>" between the
                    songs that you choose (for example, <a onclick="fromMozartToMotorhead()" href="#">from Mozart to
                        Motörhead</a>). Try playing around with the <a
                        href="https://towardsdatascience.com/how-to-discover-new-music-on-spotify-with-artificial-intelligence-b2110af6a611"
                        target="_blank">creativity</a> parameter.</h5>
                <h5>This was my Masters in Deep Learning project at <a href="https://www.mbitschool.com/"
                        target="_blank">MBIT School</a>. If you want to learn about it works, check out this <a
                        href="https://towardsdatascience.com/create-automatic-playlists-by-using-deep-learning-to-listen-to-the-music-b72836c24ce2"
                        target="_blank">article</a>.</h5>
                <h5><span style="color: red;">*NEW* </span><a href="bandcamp-radio.html" target="_blank">Bandcamp
                        radio</a> creates playlists on the fly based on a random Bandcamp track or a Spotify track of
                    your choosing. Keep checking back as I am constantly adding new tracks to the database.</h5>
                <div class="row align-items-center">
                    <div class="col">
                        <div class="text-center">
                            <a href='https://play.google.com/store/apps/details?id=online.deejai.www&pcampaignid=MKT-Other-global-all-co-prtnr-py-PartBadge-Mar2515-1'
                                target="_blank"><img alt='Get it on Google Play'
                                    src='https://play.google.com/intl/en_us/badges/images/generic/en_badge_web_generic.png'
                                    width="150px" /></a>
                        </div>
                    </div>
                </div>
            </div>
            <br>
        </p>
    </div>

    <!--Footer-->
    <div class="footer">
        <div class="row align-items-center bg-secondary">
            <div class="col-sm-3">
            </div>
            <div class="col">
                <a href="https://www.linkedin.com/shareArticle?&url=https://deej-ai.online" target="_blank"
                    class="fa fa-linkedin"></a>
            </div>
            <div class="col">
                <a href="https://www.facebook.com/sharer/sharer.php?u=https://deej-ai.online" target="_blank"
                    class="fa fa-facebook"></a>
            </div>
            <div class="col">
                <a href="https://twitter.com/intent/tweet?text=Check%20this%20https://deej-ai.online%20@att_coach"
                    target="_blank" class="fa fa-twitter"></a>
            </div>
            <div class="col">
                <a href="https://www.reddit.com/submit?url=https://deej-ai.online&title=Deej-A.I.%20-%20Automatically%20generate%20playlists%20based%20on%20how%20the%20music%20sounds
" target="_blank" class="fa fa-reddit"></a>
            </div>
            <div class="col">
                <a href="https://medium.com/@teticio" target="_blank" class="fa fa-medium"></a>
            </div>
            <div class="col">
                <a href="https://github.com/teticio/Deej-A.I." target="_blank" class="fa fa-github"></a>
            </div>
            <div class="col-sm-3">
            </div>
        </div>
    </div>
    <script>
</body>

</html>
<?php

    } else {
        // do stuff
        switch ($_POST['action']) {
            case 'bye':
                if (file_exists($file = $ids_dir .'/' . $_POST['id'])) {
                    unlink($file);
                }
                break;

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

            case 'go':
                getPlaylist();
                break;

            case 'search':
                searchTracks();
                break;

            case 'current':    
                print $api->getMyCurrentTrack()->item->preview_url;
                break;
        }
    }

    // garbage collection
    $dir = new DirectoryIterator($ids_dir);
    foreach ($dir as $fileinfo) {
        if (!$fileinfo->isDot() && filemtime($ids_dir .'/' . $fileinfo->getFilename()) - time() > strtotime('1 day', 0)) {
            unlink($ids_dir .'/' . $_POST['id']);
        }
    }
?>
