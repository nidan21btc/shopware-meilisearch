#!/usr/bin/env python3
"""Check guarded four-letter English typo matches against an isolated shop."""
import argparse
import collections
import html.parser
import json
import os
import random
import re
import sys
import urllib.parse
import urllib.request
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from local_http import local_origin, opener as local_opener

class Cards(html.parser.HTMLParser):
    def __init__(self): super().__init__(); self.ids = []; self.similar = False; self.status = False
    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'article' and attrs.get('data-product-id'): self.ids.append(attrs['data-product-id'])
        if 'data-el-similar' in attrs: self.similar = True
        if 'data-el-result-status' in attrs: self.status = True

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--index-map', type=Path, required=True)
    parser.add_argument('--engine', required=True)
    parser.add_argument('--shop', required=True)
    parser.add_argument('--host', required=True)
    args = parser.parse_args()
    engine, shop = local_origin(args.engine), local_origin(args.shop)
    if not re.fullmatch(r'[a-zA-Z0-9.-]+', args.host): parser.error('Invalid local shop host')
    key = os.environ.get('SHOPWARE_MEILISEARCH_KEY', '')
    index = json.loads(args.index_map.read_text(encoding='utf-8'))['en-GB']
    if not key or not re.fullmatch(r'[a-zA-Z0-9_-]+', index): parser.error('A local key and valid English index are required')
    opener = local_opener()
    def engine_request(path, body=None):
        request = urllib.request.Request(engine + path, data=json.dumps(body).encode() if body is not None else None,
                                         headers={'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'})
        with opener.open(request, timeout=30) as response: return json.load(response)
    def hits(q):
        return engine_request('/indexes/' + index + '/search', {'q': q, 'page': 1, 'hitsPerPage': 10000,
            'attributesToRetrieve': ['id'], 'showRankingScoreDetails': True, 'matchingStrategy': 'frequency'})['hits']
    def shop_search(q, similar=False):
        params = {'q': q}
        if similar is not None: params['similar'] = '1' if similar else '0'
        url = shop + '/en/shopware-meilisearch?' + urllib.parse.urlencode(params)
        with opener.open(urllib.request.Request(url, headers={'Host': args.host}), timeout=45) as response:
            result = Cards(); result.feed(response.read().decode('utf-8'))
        if not result.status: raise AssertionError('Unexpected storefront response')
        return result
    settings = engine_request('/indexes/' + index + '/settings/typo-tolerance')
    if settings['minWordSizeForTypos']['oneTypo'] != 4: raise ValueError('Rebuild the English index before this test')
    docs = []
    while True:
        batch = engine_request('/indexes/' + index + '/documents?limit=1000&offset=' + str(len(docs)))['results']
        docs.extend(batch)
        if len(batch) < 1000: break
    random.Random(928).shuffle(docs)
    words, seen = [], set()
    for doc in docs:
        for word in re.findall(r'(?<![^\W\d_])[^\W\d_]{4}(?![^\W\d_])', doc.get('name', '').casefold(), re.UNICODE):
            if word not in seen: seen.add(word); words.append(word)
        if len(words) > 150: break
    exact_cases = 0
    for word in words:
        found = hits(word)
        exact = [h for h in found if h['_rankingScoreDetails']['typo']['typoCount'] == 0]
        typo = [h for h in found if h['_rankingScoreDetails']['typo']['typoCount'] > 0]
        if not 1 <= len(exact) < 20 or not typo: continue
        ordinary, expanded, default = shop_search(word), shop_search(word, True), shop_search(word, None)
        if not ordinary.similar or not ordinary.ids: continue
        assert set(ordinary.ids).issubset(expanded.ids), 'Showing similar matches lost an exact product from the first page'
        assert default.ids == expanded.ids and default.similar, 'English default did not show similar results'
        exact_cases += 1
        if exact_cases >= 10: break
    assert exact_cases >= 5, 'Not enough short words with exact and similar matches in this catalog'
    recovered = 0; tested = set()
    for doc in docs:
        if doc.get('groupId') == doc['id']: continue
        for word in re.findall(r'(?<![^\W\d_])[^\W\d_]{5}(?![^\W\d_])', doc.get('name', '').casefold(), re.UNICODE):
            query = word[:2] + word[3:]
            if query in tested: continue
            tested.add(query); found = hits(query)
            if not found or any(h['_rankingScoreDetails']['typo']['typoCount'] == 0 for h in found): continue
            if not any(h['id'] == doc['id'] for h in found[:20]): continue
            result = shop_search(query)
            if doc['id'] in result.ids:
                assert not result.similar, 'No exact matches exist to hide the recovered typo result'
                recovered += 1
                break
        if recovered >= 5: break
    assert recovered >= 5, 'Not enough synthetic recoverable short typos in this catalog'
    print(f'English short-query checks: {exact_cases} guarded exact cases and {recovered} recovered typo cases.')

if __name__ == '__main__': main()
