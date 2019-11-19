<?php
    // login button for spotify
    // handle errors
    // check origin of request coming from deej-ai.online
    // log
    // rotate screen in app?
    // cookies

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
    function getPlaylist($client_id, $session) {
        global $spotify_server;
        global $ids_dir;

        $postdata = [
            'access_token' => $session->getAccessToken(),
            'username' => 'teticio',
            'playlist' => 'Well hello!',
            'tracks' => [
                '7gJLBHI6q2ca0JzfNHwXjm',
                '58iP9J86ksOPwbo0pWOafk'
            ],
            'replace' => '1',
            'size' => '10',
            'creativity' => '1',
            'noise' => '0'
        ];

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
    function searchTracks($url = null) {
        global $spotify_server;
        global $ids_dir;

        if ($url == null) {
            // search for string
            $postdata = [
                'search_string' => 'James brown get'
            ];
        } else {
            // search for similar sounding tracks
            $postdata = [
                'track_url' => $url
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
<?php if (!isset($_GET['token'])) { ?>
    <input type='submit' class='button' id='login' value='login' />
<?php } ?>
    <input type='submit' class='button' id='go' value='go' />
    <input type='submit' class='button' id='search' value='search' />
    <input type='submit' class='button' id='current' value='current' />
    <input type='submit' class='button' id='similar' value='similar' />
    <div id='current_track'></div>
    <div id='results'></div>
    <div id='playlist'></div>

    <script>
        $(document).ready(function() {
            const getUniqueID = () => {
                const s4 = () => Math.floor((1 + Math.random()) * 0x10000).toString(16).substring(1);
                return s4() + s4() + '-' + s4();
            };
            var id = null;

            $(window).on('unload', function(e) {
                if (id) {
                    $.post(window.location.href, 'action=bye&id=' + id);
                }
            });

            $('#login').click(function() {
                $.post(window.location.href, 'action=login', function(data, status) {
                    window.location.href = data;
                });
            });

            $('#go').click(function() {
                if (id) {
                    $.post(window.location.href, 'action=bye&id=' + id);
                }
                id = getUniqueID();
                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                var done = 0;
                xhr.onprogress = function() {
                    chunk = xhr.responseText.substring(done);
                    $('#playlist').append(chunk);
                    done = done + chunk.length;
                }
                xhr.send('action=go&id=' + id);
            });

            $('#search').click(function() {
                $.post(window.location.href, 'action=search', function(data, status) {
                    $('#results').html(data);
                });
            });

            $('#current').click(function() {
                $.post(window.location.href, 'action=current', function(data, status) {
                    $('#current_track').html(data);
                });
            });

            $('#similar').click(function() {
                $.post(window.location.href, 'action=similar&url=' + $('#current_track').html(), function(data, status) {
                    $('#results').html(data);
                });
            });                
        });
    </script>                        
</body>
</html>
<?php

    } else {
        // do stuff
        switch ($_POST['action']) {
            case 'bye':
                if (isset($_POST['id'])) {
                    unlink($ids_dir .'/' . $_POST['id']);
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
                getPlaylist($_POST['id'], $session);
                break;

            case 'search':
                searchTracks();
                break;

            case 'current':    
                print $api->getMyCurrentTrack()->item->preview_url;
                break;

            case 'similar':
                if (isset($_POST['url'])) {
                    searchTracks($_POST['url']);
                }
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
