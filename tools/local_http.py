"""HTTP utilities for local search evaluation; no proxy or redirects."""
import urllib.parse
import urllib.request

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise RuntimeError('Local evaluation requests must not follow redirects')

def local_origin(value):
    try:
        url = urllib.parse.urlsplit(value)
        port = url.port  # Reject malformed or out-of-range ports.
    except ValueError as error:
        raise ValueError('Expected a loopback HTTP(S) origin') from error
    if (url.scheme not in ('http', 'https') or url.hostname not in ('127.0.0.1', '::1')
            or url.username is not None or url.password is not None or '?' in value or '#' in value
            or url.path not in ('', '/') or port == 0):
        raise ValueError('Expected a loopback HTTP(S) origin without credentials, path, query or fragment')
    return value.rstrip('/')

def opener():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
