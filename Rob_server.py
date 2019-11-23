import os
os.environ["CUDA_VISIBLE_DEVICES"] = ''
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'

import warnings
warnings.simplefilter("ignore")

import tensorflow as tf
tf.compat.v1.logging.set_verbosity(tf.compat.v1.logging.ERROR)

import sys
import uuid
import pickle
import random
import logging
import requests
import numpy as np
import spotipy
from spotipy.oauth2 import SpotifyClientCredentials
from keras.models import load_model
from flask import Flask, Response
from flask import request
from flask import jsonify
from flask import has_request_context, request
from utils import most_similar
from utils import get_similar_vec
from utils import most_similar_by_vec
from utils import download_file_from_google_drive

app = Flask(__name__)
app.logger.setLevel(logging.INFO)

client_id = '1a7897e3c69d4684aa4d8e90d5911594'
client_secret = 'c60a83ca283449afb39e63841a1af60d'

epsilon_distance = 0.001
lookback = 3
noise = 0
playlist_cache = {}
client_playlists = {}


def make_playlist(urltovec,
                  seed_tracks,
                  track_ids,
                  lookback=3,
                  noise=0,
                  vecs=None):
    if vecs is not None:
        # vector seed
        candidates = most_similar_by_vec([urltovec], [1], [vecs])
        playlist = [track_ids[int(candidates[0][0][0])]]
        app.logger.info(f'{len(playlist)}. {playlist[-1]}')
        yield playlist[-1]

    else:
        # track seed
        playlist = seed_tracks
        for i in range(0, len(seed_tracks)):
            app.logger.info(f'{i+1}. {seed_tracks[i]}')
            yield seed_tracks[i]

    while True:
        if len(playlist) > lookback:
            vecs = None
        candidates = most_similar([urltovec], [1],
                                  positive=playlist[-lookback:],
                                  noise=noise,
                                  vecs=vecs)
        for candidate in candidates:
            track_id = track_ids[int(candidate[0][0])]
            if track_id not in playlist:
                break
        playlist.append(track_id)
        app.logger.info(f'{len(playlist)}. {playlist[-1]}')
        yield playlist[-1]


def search_spotify(string):
    offset = 0
    info = []
    if string == '':
        return info
    tries = 50
    while len(info) < 100:
        try:
            client_credentials_manager = SpotifyClientCredentials(
                client_id=client_id, client_secret=client_secret)
            sp = spotipy.Spotify(
                client_credentials_manager=client_credentials_manager)
            results = sp.search(q=string, limit=50, offset=offset)
            for track in results['tracks']['items']:
                if len(info) >= 100:
                    break
                if 'preview_url' in track and track['preview_url'] is not None:
                    info.append({
                        'track': track['name'],
                        'artist': track['artists'][0]['name'],
                        'album': track['album']['name'],
                        'preview_url': track['preview_url'],
                    })
            if len(results['tracks']['items']) == 50:
                offset += 50
            else:
                break

        except Exception as e:
            if 'Not found' in e.msg or tries <= 0:
                break
            tries -= 1
            app.logger.error(e)

    return info


def add_playlist_to_client(client_id, playlist_id):
    if client_id is not None:
        if client_id not in client_playlists:
            client_playlists[client_id] = set()
        client_playlists[client_id].add(playlist_id)
        app.logger.info('added playlist ' + playlist_id)


def remove_client(client_id):
    if client_id is not None and client_id in client_playlists:
        for playlist_id in client_playlists[client_id]:
            if playlist_id in playlist_cache:
                del playlist_cache[playlist_id]
                app.logger.info('removed playlist ' + playlist_id)
        del client_playlists[client_id]


# allow for cross domain requests
@app.after_request
def after_request(response):
    response.headers.add('Access-Control-Allow-Origin', '*')
    response.headers.add('Access-Control-Allow-Headers',
                         'Content-Type,Authorization')
    response.headers.add('Access-Control-Allow-Methods', 'GET,PUT,POST,DELETE')
    return response


@app.route('/rob_server', methods=['POST'])
def post():
    response = ''
    content = request.get_json()
    app.logger.info(content)

    if 'search_spotify' in content:
        string = content['search_spotify']
        response = jsonify(search_spotify(string))

    elif 'mp3' in content:
        client_id = content.get('client_id', None)
        mp3 = random.choice(list(mp3tovec.keys()))
        playlist_id = str(uuid.uuid4())
        playlist_cache[playlist_id] = make_playlist(mp3tovec,
                                                    [mp3],
                                                    track_ids,
                                                    lookback=lookback,
                                                    noise=noise)
        add_playlist_to_client(client_id, playlist_id)
        response = playlist_id

    elif 'playlist_id' in content:
        playlist_id = content['playlist_id']
        if playlist_id in playlist_cache:
            mp3 = next(playlist_cache[playlist_id])
            response = mp3
        else:
            app.logger.error(f'Missing playlist {playlist_id}')

    elif 'spotify_url' in content:
        client_id = content.get('client_id', None)
        spotify_url = content['spotify_url']
        vecs = get_similar_vec(spotify_url, model, graph, mp3tovec, track_ids)
        if vecs is not None:
            playlist_id = str(uuid.uuid4())
            playlist_cache[playlist_id] = make_playlist(
                mp3tovec,
                None,
                track_ids,
                lookback=lookback,
                noise=noise,
                vecs=vecs)
            add_playlist_to_client(client_id, playlist_id)
            response = playlist_id

    elif 'remove_playlists_for_client' in content:
        client_id = content['remove_playlists_for_client']
        remove_client(client_id)

    elif 'num_tracks' in content:
        response = str(len(mp3tovec))

    return response


if __name__ == '__main__':
    download_file_from_google_drive('1LM1WW1GCGKeFD1AAHS8ijNwahqH4r4xV',
                                    'speccy_model')

    mp3tovec = pickle.load(open('mp3tovec.p', 'rb'))
    mp3tovec = dict(
        zip(mp3tovec,
            map(lambda x: mp3tovec[x] / np.linalg.norm(mp3tovec[x]),
                mp3tovec)))
    track_ids = list(mp3tovec.keys())

    model = load_model('speccy_model')
    model._make_predict_function()
    graph = tf.compat.v1.get_default_graph()

    port = int(sys.argv[1]) if len(sys.argv) > 1 else 5124
    app.run(debug=False, port=port)