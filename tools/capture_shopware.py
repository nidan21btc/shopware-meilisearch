#!/usr/bin/env python3
"""Capture the first rendered Shopware page for a list of comparison queries."""
import argparse
import json
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path


class Cards(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = []

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'article' and 'el-search__card' in attrs.get('class', '').split():
            self.ids.append(attrs['data-product-id'])


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--queries', required=True, type=Path, help='Reference snapshot specifying queries by locale')
    p.add_argument('--url', required=True, help='Origin of an isolated test shop')
    p.add_argument('--host', help='Optional local virtual-host header')
    p.add_argument('--output', required=True, type=Path)
    args = p.parse_args()
    from local_http import local_origin, opener as local_opener
    try:
        args.url = local_origin(args.url)
    except ValueError as error:
        p.error(str(error))
    opener = local_opener()
    reference = json.loads(args.queries.read_text(encoding='utf-8'))
    captured = {}
    for locale, cases in reference.items():
        captured[locale] = []
        for case in cases:
            path = '/' + locale[:2].lower() + '/shopware-meilisearch?' + urllib.parse.urlencode({'q': case['query']})
            headers = {'X-Requested-With': 'XMLHttpRequest'}
            if args.host:
                headers['Host'] = args.host
            req = urllib.request.Request(args.url.rstrip('/') + path, headers=headers)
            with opener.open(req, timeout=30) as response:
                html = response.read().decode('utf-8')
            if 'data-el-result-status' not in html:
                raise ValueError(f'Unexpected response for {locale}')
            parser = Cards()
            parser.feed(html)
            captured[locale].append({'query': case['query'], 'ids': parser.ids[:20]})
        print(locale, len(captured[locale]), 'queries')
    from private_output import write_private_text
    write_private_text(args.output, json.dumps(captured, ensure_ascii=False, indent=2) + '\n')


if __name__ == '__main__':
    main()
