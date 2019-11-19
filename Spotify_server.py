from flask import Flask, Response
from flask import request
from flask import jsonify
from flask import has_request_context, request
import logging
import os

app = Flask(__name__)

logging.basicConfig(level=logging.ERROR)
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'
os.environ["CUDA_VISIBLE_DEVICES"] = ''

import re
import numpy as np
import pickle
import argparse
import spotipy
import spotipy.util as util
import random
import requests
import json
from io import BytesIO
import shutil
import tensorflow as tf
from keras.models import load_model
import librosa

epsilon_distance = 0.001
lookback = 3
max_results = 100
max_size = 100
max_num_tracks = 5


def download_file_from_google_drive(id, destination):
    if os.path.isfile(destination):
        return None
    print(f'Downloading {destination}')
    URL = "https://docs.google.com/uc?export=download"
    session = requests.Session()
    response = session.get(URL, params={'id': id}, stream=True)
    token = get_confirm_token(response)
    if token:
        params = {'id': id, 'confirm': token}
        response = session.get(URL, params=params, stream=True)
    save_response_content(response, destination)


def get_confirm_token(response):
    for key, value in response.cookies.items():
        if key.startswith('download_warning'):
            return value
    return None


def save_response_content(response, destination):
    CHUNK_SIZE = 32768
    with open(destination, "wb") as f:
        for chunk in response.iter_content(CHUNK_SIZE):
            if chunk:  # filter out keep-alive new chunks
                f.write(chunk)


def add_track_to_playlist(sp, username, playlist_id, track_id, replace=False):
    if sp is not None and username is not None and playlist_id is not None:
        try:
            if replace:
                result = sp.user_playlist_replace_tracks(
                    username, playlist_id, [track_id])
            else:
                result = sp.user_playlist_add_tracks(username, playlist_id,
                                                     [track_id])
        except spotipy.client.SpotifyException:
            pass


def most_similar(mp3tovecs, weights, positive=[], negative=[], noise=0):
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


# create a musical journey between given track "waypoints"
def join_the_dots(client_id, sp, username, playlist_id, mp3tovecs, weights, ids, \
                  tracks, track_ids, n=5, noise=0, replace=True):
    if playlist_id:
        yield 'playlist_id:' + playlist_id + ' '
    playlist = []
    playlist_tracks = [tracks[_] for _ in ids]
    end = start = ids[0]
    start_vec = [mp3tovec[start] for k, mp3tovec in enumerate(mp3tovecs)]
    for end in ids[1:]:
        end_vec = [mp3tovec[end] for k, mp3tovec in enumerate(mp3tovecs)]
        playlist.append(start)
        add_track_to_playlist(sp, username, playlist_id, playlist[-1], replace
                              and len(playlist) == 1)
        app.logger.info(f'{len(playlist)}.* {tracks[playlist[-1]]}')
        yield playlist[-1] + ' '
        for i in range(n):
            if (client_id and not os.path.exists('./ids/' + client_id)):
                # the user has gone away
                break
            candidates = most_similar_by_vec(mp3tovecs,
                                             weights,
                                             [[(n - i + 1) / n * start_vec[k] +
                                               (i + 1) / n * end_vec[k]]
                                              for k in range(len(mp3tovecs))],
                                             noise=noise)
            for candidate in candidates:
                track_id = track_ids[int(candidate[0][0])]
                if track_id not in playlist + ids and tracks[
                        track_id] not in playlist_tracks and tracks[
                            track_id][:tracks[track_id].find(' - ')] != tracks[
                                playlist[-1]][:tracks[playlist[-1]].find(' - ')]:
                    break
            playlist.append(track_id)
            playlist_tracks.append(tracks[track_id])
            add_track_to_playlist(sp, username, playlist_id, playlist[-1])
            app.logger.info(f'{len(playlist)}. {tracks[playlist[-1]]}')
            yield playlist[-1] + ' '
        start = end
        start_vec = end_vec
    playlist.append(end)
    add_track_to_playlist(sp, username, playlist_id, playlist[-1])
    app.logger.info(f'{len(playlist)}.* {tracks[playlist[-1]]}')
    yield playlist[-1] + ' '


def make_playlist(client_id, sp, username, playlist_id, mp3tovecs, weights, seed_tracks, \
                  tracks, track_ids, size=10, lookback=3, noise=0, replace=True):
    if playlist_id:
        yield 'playlist_id:' + playlist_id + ' '
    playlist = seed_tracks
    playlist_tracks = [tracks[_] for _ in playlist]
    for i in range(0, len(seed_tracks)):
        add_track_to_playlist(sp, username, playlist_id, playlist[i], replace
                              and len(playlist) == 1)
        app.logger.info(f'{i+1}.* {tracks[seed_tracks[i]]}')
        yield seed_tracks[i] + ' '
    for i in range(len(seed_tracks), size):
        if (client_id and not os.path.exists('./ids/' + client_id)):
            # the user has gone away
            break
        candidates = most_similar(mp3tovecs,
                                  weights,
                                  positive=playlist[-lookback:],
                                  noise=noise)
        for candidate in candidates:
            track_id = track_ids[int(candidate[0][0])]
            if track_id not in playlist and tracks[
                    track_id] not in playlist_tracks and tracks[
                        track_id][:tracks[track_id].find(' - ')] != tracks[
                            playlist[-1]][:tracks[playlist[-1]].find(' - ')]:
                break
        playlist.append(track_id)
        playlist_tracks.append(tracks[track_id])
        add_track_to_playlist(sp, username, playlist_id, playlist[-1])
        app.logger.info(f'{i+1}. {tracks[playlist[-1]]}')
        yield playlist[-1] + ' '


def user_playlist_create(sp,
                         username,
                         playlist_name,
                         description='',
                         public=True):
    data = {
        'name': playlist_name,
        'public': public,
        'description': description
    }
    return sp._post("users/%s/playlists" % (username, ), payload=data)['id']


def get_similar(client_id, track_url, model, graph, mp3tovec, track_ids):
    sr = 22050
    n_fft = 2048
    hop_length = 512
    n_mels = model.layers[0].input_shape[1]
    slice_size = model.layers[0].input_shape[2]
    slice_time = slice_size * hop_length / sr

    try:
        r = requests.get(track_url, allow_redirects=True)
        if r.status_code != 200:
            return []
        with open(f'{client_id}.mp3',
                  'wb') as file:  # this is really annoying!
            shutil.copyfileobj(BytesIO(r.content), file, length=131072)
        y, sr = librosa.load(f'{client_id}.mp3', mono=True)
        # cannot safely process two calls from same client
        os.remove(f'{client_id}.mp3')
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
        candidates = most_similar_by_vec([mp3tovec], [1], [vecs])
        return [track_ids[int(candidate[0][0])] for candidate in candidates]

    except Exception as e:
        app.logger.info(e)
        if os.path.exists(f'./{client_id}.mp3'):
            os.remove(f'./{client_id}.mp3')
        return []


# allow for cross domain requests
@app.after_request
def after_request(response):
    response.headers.add('Access-Control-Allow-Origin', '*')
    response.headers.add('Access-Control-Allow-Headers',
                         'Content-Type,Authorization')
    response.headers.add('Access-Control-Allow-Methods', 'GET,PUT,POST,DELETE')
    return response


@app.route('/spotify_server', methods=['POST'])
def post():
    content = request.get_json()

    if 'search_string' in content:
        search_string = re.sub(r'([^\s\w]|_)+', '',
                               content['search_string'].lower()).split()
        ids = sorted([
            track for track in mp3tovecs
            if all(word in re.sub(r'([^\s\w]|_)+', '', tracks[track].lower())
                   for word in search_string)
        ],
                     key=lambda x: tracks[x])[:max_results]
        response = []
        for i, id in enumerate(ids):
            response.append({'track': tracks[id], 'id': id})
        return jsonify(response)

    if 'track_url' in content:
        client_id = content.get('client_id', None)
        track_url = content.get('track_url', None)
        ids = get_similar(client_id, track_url, model, graph, mp3tovecs,
                          track_ids)[:10]
        response = []
        for i, id in enumerate(ids):
            response.append({'track': tracks[id], 'id': id})
        return jsonify(response)

    sp = username = playlist_id = None
    if 'access_token' in content:
        token = content['access_token']
        username = content['username']
        playlist_name = content['playlist']

        if playlist_name != '':
            sp = spotipy.Spotify(token)
            if sp is not None:
                try:
                    playlists = sp.user_playlists(username)
                    if playlists is not None:
                        playlist_ids = [
                            playlist['id'] for playlist in playlists['items']
                            if playlist['name'] == playlist_name
                        ]
                        if len(playlist_ids) > 0:
                            playlist_id = playlist_ids[0]
                        else:
                            if 'tracks' in content:
                                # spotipy create_user_playlist is broken
                                playlist_id = user_playlist_create(
                                    sp, username, playlist_name,
                                    'Created by Deej-A.I. http://deej-ai.online'
                                )
                except:
                    pass
            if playlist_id is None:
                app.logger.info(
                    f'Unable to access playlist {playlist_name} for user {username}'
                )
            else:
                app.logger.info(f'Playlist {playlist_id}')

    if 'tracks' in content:
        input_tracks = content['tracks'][:max_num_tracks]
        replace = content['replace'] == '1'
        size = min(int(content['size']), max_size)
        creativity = float(content['creativity'])
        noise = float(content['noise'])
        client_id = content.get('client_id', None)

        if len(input_tracks) == 0:
            ids = [track for track in mp3tovecs]
            input_tracks.append(ids[random.randint(0, len(ids))])

        if len(input_tracks) > 1:
            track_id = join_the_dots(client_id,
                                     sp,
                                     username,
                                     playlist_id, [mp3tovecs, tracktovecs],
                                     [creativity, 1 - creativity],
                                     input_tracks,
                                     tracks,
                                     track_ids,
                                     n=size,
                                     noise=noise,
                                     replace=replace)
        else:
            track_id = make_playlist(client_id,
                                     sp,
                                     username,
                                     playlist_id, [mp3tovecs, tracktovecs],
                                     [creativity, 1 - creativity],
                                     input_tracks,
                                     tracks,
                                     track_ids,
                                     size=size,
                                     lookback=lookback,
                                     noise=noise,
                                     replace=replace)
        return Response(track_id, content_type='application/octet-stream')

    return 'playlist_id:' + playlist_id if playlist_id else ''


if __name__ == '__main__':
    download_file_from_google_drive('1Mg924qqF3iDgVW5w34m6Zaki5fNBdfSy',
                                    'spotifytovec.p')
    download_file_from_google_drive('1geEALPQTRBNUvkpI08B-oN4vsIiDTb5I',
                                    'tracktovec.p')
    download_file_from_google_drive('1Qre4Lkym1n5UTpAveNl5ffxlaAmH1ntS',
                                    'spotify_tracks.p')
    download_file_from_google_drive('1LM1WW1GCGKeFD1AAHS8ijNwahqH4r4xV',
                                    'speccy_model')

    mp3tovecs = pickle.load(open('spotifytovec.p', 'rb'))
    mp3tovecs = dict(
        zip(mp3tovecs.keys(),
            [mp3tovecs[_] / np.linalg.norm(mp3tovecs[_]) for _ in mp3tovecs]))

    tracktovecs = pickle.load(open('tracktovec.p', 'rb'))
    tracktovecs = dict(
        zip(tracktovecs.keys(), [
            tracktovecs[_] / np.linalg.norm(tracktovecs[_])
            for _ in tracktovecs
        ]))

    tracks = pickle.load(open('spotify_tracks.p', 'rb'))
    track_ids = [_ for _ in mp3tovecs]

    model = load_model('speccy_model')
    model._make_predict_function()
    graph = tf.compat.v1.get_default_graph()

    app.run(debug=False, port=5123)
