import unittest
from compare_search_snapshots import Results


class SnapshotParserTest(unittest.TestCase):
    def test_products_facets_and_counts(self):
        parser = Results()
        parser.feed('''<section data-el-result-status="2 results">
          <article data-product-id="first"></article><article data-product-id="second"></article>
          <label data-el-facet-value><input name="category[]" value="cat" checked>
            <span class="el-search__facet-count"> 2* </span></label>
          <label data-el-facet-value><input name="manufacturer[]" value="brand">
            <span class="el-search__facet-count">1</span></label></section>''')
        self.assertEqual(parser.status, '2 results')
        self.assertEqual(parser.products, ['first', 'second'])
        self.assertEqual(parser.facets, [
            {'type':'category[]', 'id':'cat', 'checked':True, 'count':'2*'},
            {'type':'manufacturer[]', 'id':'brand', 'checked':False, 'count':'1'},
        ])

    def test_unrelated_input_is_ignored(self):
        parser = Results()
        parser.feed('<label data-el-facet-value><input name="other" value="x"></label>')
        self.assertEqual(parser.facets, [])


if __name__ == '__main__':
    unittest.main()
