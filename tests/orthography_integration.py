#!/usr/bin/env python3
"""Read-only German spelling-fallback check against an isolated test shop."""
import sys
from pathlib import Path as _Path
sys.path.insert(0, str(_Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener as local_opener

import argparse
import json
import os
import random
import re
import urllib.parse
import urllib.request
from pathlib import Path


def loopback(value):
    url = urllib.parse.urlsplit(value)
    if url.scheme != 'http' or url.hostname != '127.0.0.1' or url.username or url.password or url.query or url.fragment or url.path not in ('', '/'):
        raise ValueError('Expected a local HTTP loopback origin')
    return value.rstrip('/')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--index-map', type=Path, required=True)
    p.add_argument('--engine', required=True)
    p.add_argument('--shop', required=True)
    p.add_argument('--host', required=True)
    a = p.parse_args()
    engine, shop = loopback(a.engine), loopback(a.shop)
    if not re.fullmatch(r'[a-zA-Z0-9.-]+', a.host):
        p.error('Invalid local shop host')
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY', '')
    index = json.loads(a.index_map.read_text())['de-DE']
    if not key or not re.fullmatch(r'[a-zA-Z0-9_-]+', index):
        p.error('A local key and valid German index are required')
    opener = local_opener()

    def engine_request(path, body=None):
        request = urllib.request.Request(engine + path, data=json.dumps(body).encode() if body is not None else None,
            headers={'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'})
        with opener.open(request, timeout=30) as response:
            return json.load(response)

    def search(query):
        return engine_request('/indexes/' + index + '/search', {'q': query, 'limit': 1000, 'attributesToRetrieve': ['id']})['hits']

    def storefront(locale, query):
        request = urllib.request.Request(shop + '/' + locale + '/shopware-meilisearch?' + urllib.parse.urlencode({'q': query}), headers={'Host': a.host})
        with opener.open(request, timeout=30) as response:
            return response.read().decode()

    documents = []
    while True:
        batch = engine_request('/indexes/' + index + '/documents?limit=1000&offset=' + str(len(documents)))['results']
        documents.extend(batch)
        if len(batch) < 1000:
            break
    random.Random(1949).shuffle(documents)
    seen = set()
    checked = recovered = preserved = 0
    for doc in documents:
        for word in re.findall(r'[^\W\d_]{4,}', doc.get('name', '').casefold(), re.UNICODE):
            if not any(ch in word for ch in 'äöüß') or word in seen:
                continue
            seen.add(word)
            query = word.translate(str.maketrans({'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss'}))
            hits = search(query)
            if not hits:
                html = storefront('de', query)
                if 'data-product-id="' + doc['id'] + '"' in html:
                    assert 'Ergebnisse für eine andere Schreibweise.' in html, 'Missing fallback explanation'
                    assert 'alternative spelling' not in storefront('en', query).lower(), 'Fallback crossed into English'
                    recovered += 1
            elif not preserved:
                assert 'Ergebnisse für eine andere Schreibweise.' not in storefront('de', query), 'Fallback replaced existing results'
                preserved += 1
            checked += 1
            break
        if checked >= 120:
            break
    if recovered == 0 or preserved == 0:
        raise ValueError('No suitable empty and nonempty synthetic cases in this catalog')
    print('German spelling fallback recovered', recovered, 'synthetic targets; existing and English searches were preserved.')


if __name__ == '__main__':
    main()
