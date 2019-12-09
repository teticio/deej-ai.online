import os
import uuid
import shutil
import librosa
import requests
import numpy as np
from io import BytesIO


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


def most_similar(mp3tovecs,
                 weights,
                 positive=[],
                 negative=[],
                 noise=0,
                 vecs=None):
    mp3_vecs_i = np.array([weights[j] *
        np.sum([mp3tovecs[i, j] for i in positive] +
               [-mp3tovecs[i, j] for i in negative],
               axis=0) for j in range(len(weights))])
    if vecs is not None:
        mp3_vecs_i += np.sum(vecs, axis=0)
    if noise != 0:
        for i, mp3_vec_i in enumerate(mp3_vecs_i):
            mp3_vec_i += np.random.normal(0, noise * np.linalg.norm(mp3_vec_i), mp3tovecs.shape[2])
    result = list(
        np.argsort(np.tensordot(mp3tovecs, mp3_vecs_i, axes=((1, 2), (0, 1)))))
    for i in negative:
        del result[result.index(i)]
    result.reverse()
    for i in positive:
        del result[result.index(i)]
    return result


def most_similar_by_vec(mp3tovecs,
                        weights,
                        positives=[],
                        negatives=[],
                        noise=0):
    mp3_vecs_i = np.array([weights[j] *
        np.sum(positives[j] if positives else [] +
               -negatives[j] if negatives else [],
               axis=0) for j in range(len(weights))])
    if noise != 0:
        for i, mp3_vec_i in enumerate(mp3_vecs_i):
            mp3_vec_i += weights[i] * np.random.normal(0, noise, mp3tovecs.shape[2])
    result = list(
        np.argsort(-np.tensordot(mp3tovecs, mp3_vecs_i, axes=((1, 2), (0, 1)))))
    return result


def get_similar_vec(track_url, model, graph):
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
        x = np.ndarray(shape=(S.shape[1] // slice_size + 1, n_mels, slice_size, 1),
                       dtype=float)
        for slice in range(S.shape[1] // slice_size):
            log_S = librosa.power_to_db(S[:, slice * slice_size:(slice + 1) *
                                          slice_size],
                                        ref=np.max)
            if np.max(log_S) - np.min(log_S) != 0:
                log_S = (log_S - np.min(log_S)) / (np.max(log_S) - np.min(log_S))
            x[slice, :, :, 0] = log_S
        # hack because Spotify samples are a shade under 30s
        log_S = librosa.power_to_db(S[:, -slice_size:], ref=np.max)
        if np.max(log_S) - np.min(log_S) != 0:
            log_S = (log_S - np.min(log_S)) / (np.max(log_S) - np.min(log_S))
        x[-1, :, :, 0] = log_S
        with graph.as_default():
            vecs = model.predict(x)
        return vecs

    except:
        if os.path.exists(f'./{playlist_id}.mp3'):
            os.remove(f'./{playlist_id}.mp3')
        return None
