# TODO
# refactor common functions into utils

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
import shutil
import requests
import librosa
from io import BytesIO
import numpy as np
from keras.models import load_model
import spotipy
from spotipy.oauth2 import SpotifyClientCredentials
from flask import Flask, Response
from flask import request
from flask import jsonify
from flask import has_request_context, request
import logging

app = Flask(__name__)
app.logger.setLevel(logging.INFO)

client_id = '1a7897e3c69d4684aa4d8e90d5911594'
client_secret = 'c60a83ca283449afb39e63841a1af60d'

epsilon_distance = 0.001
lookback = 3
noise = 0
playlist_cache = {}
client_playlists = {}


def most_similar(mp3tovecs,
                 weights,
                 positive=[],
                 negative=[],
                 noise=0,
                 vecs=None):
    if isinstance(positive, str):
        positive = [positive]  # broadcast to list
    if isinstance(negative, str):
        negative = [negative]  # broadcast to list
    similar = np.zeros((len(mp3tovecs[0]), 2, len(weights)), dtype=np.float64)
    for k, mp3tovec in enumerate(mp3tovecs):
        mp3_vec_i = np.sum([mp3tovec[i] for i in positive] +
                           [-mp3tovec[i] for i in negative],
                           axis=0)
        mp3_vec_i += np.random.normal(0, noise, len(mp3_vec_i))
        if vecs is not None:
            mp3_vec_i += np.sum(vecs, axis=0)
        mp3_vec_i = mp3_vec_i / np.linalg.norm(mp3_vec_i)
        for j, track_j in enumerate(mp3tovec):
            if track_j in positive or track_j in negative:
                continue
            mp3_vec_j = mp3tovec[track_j]
            similar[j, 0, k] = j
            similar[j, 1, k] = np.dot(mp3_vec_i, mp3_vec_j)
    return sorted(similar, key=lambda x: -np.dot(x[1], weights))


def most_similar_by_vec(mp3tovecs,
                        weights,
                        positives=None,
                        negatives=None,
                        noise=0):
    similar = np.zeros((len(mp3tovecs[0]), 2, len(weights)), dtype=np.float64)
    positive = negative = []
    for k, mp3tovec in enumerate(mp3tovecs):
        if positives is not None:
            positive = positives[k]
        if negatives is not None:
            negative = negatives[k]
        if isinstance(positive, str):
            positive = [positive]  # broadcast to list
        if isinstance(negative, str):
            negative = [negative]  # broadcast to list
        mp3_vec_i = np.sum([i for i in positive] + [-i for i in negative],
                           axis=0)
        mp3_vec_i += np.random.normal(0, noise, len(mp3_vec_i))
        mp3_vec_i = mp3_vec_i / np.linalg.norm(mp3_vec_i)
        for j, track_j in enumerate(mp3tovec):
            mp3_vec_j = mp3tovec[track_j]
            similar[j, 0, k] = j
            similar[j, 1, k] = np.dot(mp3_vec_i, mp3_vec_j)
    return sorted(similar, key=lambda x: -np.dot(x[1], weights))


def make_bandcamp_playlist(urltovec,
                           seed_tracks,
                           track_ids,
                           tracks,
                           lookback=3,
                           noise=0,
                           vecs=None):
    if vecs is not None:
        # vector seed
        candidates = most_similar_by_vec([urltovec], [1], [vecs])
        playlist = [track_ids[int(candidates[0][0][0])]]
        app.logger.info(f'{len(playlist)}.* {tracks[playlist[-1]]}')
        yield playlist[-1]

    else:
        # track seed
        playlist = seed_tracks
        for i in range(0, len(seed_tracks)):
            app.logger.info(f'{i+1}.* {tracks[seed_tracks[i]]}')
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
        app.logger.info(f'{len(playlist)}.* {tracks[playlist[-1]]}')
        yield playlist[-1]


def search_spotify(string):
    offset = 0
    info = []
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
            if 'Not found' in e.msg:
                break
            app.logger.error(e)

    return info


def get_similar_vec(track_url, model, graph, mp3tovec, track_ids):
    playlist_id = str(uuid.uuid4())
    sr = 22050
    n_fft = 2048
    hop_length = 512
    n_mels = model.layers[0].input_shape[1]
    slice_size = model.layers[0].input_shape[2]

    try:
        r = requests.get(track_url, allow_redirects=True)
        if r.status_code != 200:
            return []
        with open(f'{playlist_id}.mp3',
                  'wb') as file:  # this is really annoying!
            shutil.copyfileobj(BytesIO(r.content), file, length=131072)
        y, sr = librosa.load(f'{playlist_id}.mp3', mono=True)
        # cannot safely process two calls from same client
        os.remove(f'{playlist_id}.mp3')
        S = librosa.feature.melspectrogram(y=y,
                                           sr=sr,
                                           n_fft=n_fft,
                                           hop_length=hop_length,
                                           n_mels=n_mels,
                                           fmax=sr / 2)
        # hack because Spotify samples are a shade under 30s
        x = np.ndarray(shape=(S.shape[1] // slice_size + 1, n_mels, slice_size,
                              1),
                       dtype=float)
        for slice in range(S.shape[1] // slice_size):
            log_S = librosa.power_to_db(S[:, slice * slice_size:(slice + 1) *
                                          slice_size],
                                        ref=np.max)
            if np.max(log_S) - np.min(log_S) != 0:
                log_S = (log_S - np.min(log_S)) / (np.max(log_S) -
                                                   np.min(log_S))
            x[slice, :, :, 0] = log_S
        # hack because Spotify samples are a shade under 30s
        log_S = librosa.power_to_db(S[:, -slice_size:], ref=np.max)
        if np.max(log_S) - np.min(log_S) != 0:
            log_S = (log_S - np.min(log_S)) / (np.max(log_S) - np.min(log_S))
        x[-1, :, :, 0] = log_S
        with graph.as_default():
            vecs = model.predict(x)
        return vecs

    except Exception as e:
        app.logger.error(e)
        if os.path.exists(f'./{playlist_id}.mp3'):
            os.remove(f'./{playlist_id}.mp3')
        return None


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


@app.route('/bandcamp_server', methods=['POST'])
def post():
    response = ''
    content = request.get_json()
    app.logger.info(content)

    if 'search_spotify' in content:
        string = content['search_spotify']
        response = jsonify(search_spotify(string))

    elif 'bandcamp_url' in content:
        client_id = content.get('client_id', None)
        bandcamp_url = content['bandcamp_url']
        if bandcamp_url == '':
            bandcamp_url = random.choice(list(urltovec.keys()))
        playlist_id = str(uuid.uuid4())
        playlist_cache[playlist_id] = make_bandcamp_playlist(urltovec,
                                                             [bandcamp_url],
                                                             track_ids,
                                                             tracks,
                                                             lookback=lookback,
                                                             noise=noise)
        add_playlist_to_client(client_id, playlist_id)
        response = playlist_id

    elif 'playlist_id' in content:
        playlist_id = content['playlist_id']
        if playlist_id in playlist_cache:
            url = next(playlist_cache[playlist_id])
            html = str(requests.get(url).content)
            mp3_url = html[html.find('{"mp3-128":"') + len('{"mp3-128":"'):]
            mp3_url = mp3_url[:mp3_url.find('"}')]
            jpg_url = html[html.find('tralbumArt') + len('tralbumArt'):]
            jpg_url = jpg_url[jpg_url.find('img src="') + len('img src="'):]
            jpg_url = jpg_url[:jpg_url.find('"')]
            response = jsonify((mp3_url, jpg_url, url) + tracks[url])
        else:
            app.logger.error(f'Missing playlist {playlist_id}')

    elif 'spotify_url' in content:
        client_id = content.get('client_id', None)
        spotify_url = content['spotify_url']
        vecs = get_similar_vec(spotify_url, model, graph, urltovec, track_ids)
        if vecs is not None:
            playlist_id = str(uuid.uuid4())
            playlist_cache[playlist_id] = make_bandcamp_playlist(
                urltovec,
                None,
                track_ids,
                tracks,
                lookback=lookback,
                noise=noise,
                vecs=vecs)
            add_playlist_to_client(client_id, playlist_id)
            response = playlist_id

    elif 'remove_playlists_for_client' in content:
        client_id = content['remove_playlists_for_client']
        remove_client(client_id)

    elif 'num_tracks' in content:
        response = str(len(urltovec))

    return response


if __name__ == '__main__':
    urltovec = pickle.load(open('urltovec.p', 'rb'))
    urltovec = dict(
        zip(urltovec,
            map(lambda x: urltovec[x] / np.linalg.norm(urltovec[x]),
                urltovec)))
    tracks = pickle.load(open('bandcamp_tracks.p', 'rb'))
    track_ids = list(urltovec.keys())

    model = load_model('speccy_model')
    model._make_predict_function()
    graph = tf.compat.v1.get_default_graph()

    port = int(sys.argv[1]) if len(sys.argv) > 1 else 5124
    app.run(debug=False, port=port)
