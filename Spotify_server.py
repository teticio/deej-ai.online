import os
os.environ["CUDA_VISIBLE_DEVICES"] = ''
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'

import warnings
warnings.simplefilter("ignore")

import tensorflow as tf
tf.compat.v1.logging.set_verbosity(tf.compat.v1.logging.ERROR)

import re
import sys
import json
import pickle
import random
import logging
import requests
import numpy as np
import spotipy
import spotipy.util as util
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

epsilon_distance = 0.001
lookback = 3
max_results = 100
max_size = 100
max_num_tracks = 5


def add_track_to_playlist(sp, username, playlist_id, track_id, replace=False):
    if sp is not None and username is not None and playlist_id is not None:
        try:
            if replace:
                sp.user_playlist_replace_tracks(username, playlist_id, [track_id])
            else:
                sp.user_playlist_add_tracks(username, playlist_id, [track_id])
        except spotipy.client.SpotifyException:
            pass


# create a musical journey between given track "waypoints"
def join_the_dots(client_id, sp, username, playlist_id, mp3tovecs, weights, ids, \
                  tracks, track_ids, track_indices, n=5, noise=0, replace=True):
    if playlist_id:
        yield 'playlist_id:' + playlist_id + ' '
    playlist = []
    playlist_tracks = [tracks[_] for _ in ids]
    end = start = ids[0]
    start_vec = mp3tovecs[track_indices[start]]
    for end in ids[1:]:
        end_vec = mp3tovecs[track_indices[end]]
        playlist.append(start)
        add_track_to_playlist(sp, username, playlist_id, playlist[-1], replace
                              and len(playlist) == 1)
        app.logger.info(f'{len(playlist)}.* {tracks[playlist[-1]]}')
        yield playlist[-1] + ' '
        for i in range(n):
            if (client_id and not os.path.exists('./spotify_ids/' + client_id)):
                # the user has gone away
                break
            candidates = most_similar_by_vec(mp3tovecs,
                                             weights,
                                             [[(n - i + 1) / n * start_vec[k] +
                                               (i + 1) / n * end_vec[k]]
                                              for k in range(len(weights))],
                                             noise=noise)
            for candidate in candidates:
                track_id = track_ids[candidate]
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


def make_playlist(client_id, sp, username, playlist_id, mp3tovecs, weights, playlist, \
                  tracks, track_ids, track_indices, size=10, lookback=3, noise=0, replace=True):
    if playlist_id:
        yield 'playlist_id:' + playlist_id + ' '
    playlist_tracks = [tracks[_] for _ in playlist]
    playlist_indices = [track_indices[_] for _ in playlist]
    for i in range(0, len(playlist)):
        add_track_to_playlist(sp, username, playlist_id, playlist[i], replace
                              and len(playlist) == 1)
        app.logger.info(f'{i+1}.* {tracks[playlist[i]]}')
        yield playlist[i] + ' '
    for i in range(len(playlist), size):
        if (client_id and not os.path.exists('./spotify_ids/' + client_id)):
            # the user has gone away
            break
        candidates = most_similar(mp3tovecs,
                                  weights,
                                  positive=playlist_indices[-lookback:],
                                  noise=noise)
        for candidate in candidates:
            track_id = track_ids[candidate]
            if track_id not in playlist and tracks[
                    track_id] not in playlist_tracks and tracks[
                        track_id][:tracks[track_id].find(' - ')] != tracks[
                            playlist[-1]][:tracks[playlist[-1]].find(' - ')]:
                break
        playlist.append(track_id)
        playlist_tracks.append(tracks[track_id])
        playlist_indices.append(candidate)
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
    app.logger.info(content)

    if 'search_string' in content:
        search_string = re.sub(r'([^\s\w]|_)+', '',
                               content['search_string'].lower()).split()
        ids = sorted([
            track for track in tracks
            if all(word in re.sub(r'([^\s\w]|_)+', '', tracks[track].lower())
                   for word in search_string)
        ],
                     key=lambda x: tracks[x])[:max_results]
        response = []
        for id in ids:
            response.append({'track': tracks[id], 'id': id})
        return jsonify(response)

    if 'track_url' in content:
        track_url = content.get('track_url', None)
        vecs = get_similar_vec(track_url, model, graph)
        if vecs is not None:
            # vector seed
            candidates = most_similar_by_vec(mp3tovecs[:, np.newaxis, 0, :], [1], [vecs])
            ids = [
                track_ids[candidate]
                for candidate in candidates[0:10]
            ]
            response = [{'track': tracks[id], 'id': id} for id in ids]
            return jsonify(response)
        return ''

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
                app.logger.error(
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
            input_tracks = [random.choice(track_ids)]

        if len(input_tracks) > 1:
            track_id = join_the_dots(client_id,
                                     sp,
                                     username,
                                     playlist_id,
                                     mp3tovecs,
                                     [creativity, 1 - creativity],
                                     input_tracks,
                                     tracks,
                                     track_ids,
                                     track_indices,
                                     n=size,
                                     noise=noise,
                                     replace=replace)
        else:
            track_id = make_playlist(client_id,
                                     sp,
                                     username,
                                     playlist_id,
                                     mp3tovecs,
                                     [creativity, 1 - creativity],
                                     input_tracks,
                                     tracks,
                                     track_ids,
                                     track_indices,
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

    if len(sys.argv) > 2 and sys.argv[2] == 'test':
        mp3tovecs = pickle.load(open('spotifytovec_2.p', 'rb'))
    else:
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

    track_indices = dict(map(lambda x: (x[1], x[0]), enumerate(mp3tovecs)))
    mp3tovecs = np.array([[mp3tovecs[_], tracktovecs[_]] for _ in mp3tovecs])
    del tracktovecs

    model = load_model('speccy_model')
    model._make_predict_function()
    graph = tf.compat.v1.get_default_graph()

    port = int(sys.argv[1]) if len(sys.argv) > 1 else 5050
    app.run(debug=False, port=port)
