import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts/ci'))
import release20_transition_mysql as transition


class TransitionPrivacyTest(unittest.TestCase):
    def test_private_mysql_or_data_failure_never_reaches_output(self):
        with patch.object(sys, 'argv', ['fixture', '--series', '8.0']), \
                patch.object(transition, 'rehearse', side_effect=RuntimeError('PRIVATE_SQL_HOST_ROW_SECRET')), \
                patch('sys.stdout', new_callable=io.StringIO) as out:
            self.assertEqual(2, transition.main())
            result = json.loads(out.getvalue())
            self.assertEqual('BLOCK', result['result'])
            self.assertFalse(result['release_authorized'])
            self.assertNotIn('PRIVATE', out.getvalue())

    def test_absence_contract_keeps_index_table_scope_and_global_foreign_names(self):
        before = ('table\tlegacy\ncolumn\tlegacy\t1\ta\nindex\tlegacy\told_index\n'
                  'foreign_key\tlegacy\told_foreign\n')
        after = (before + 'column\tlegacy\t2\tb\nindex\tlegacy\tnew_index\n'
                 'table\tnew_table\nindex\tnew_table\tnew_index\nforeign_key\tnew_table\tnew_foreign\n'
                 'trigger\tlegacy\tnew_trigger\n')
        result = transition.contract_from(before, after)
        self.assertEqual({'legacy': ['new_index']}, result['absent_index_names'])
        self.assertEqual(['new_foreign'], result['absent_foreign_key_names'])
        self.assertEqual(['new_table'], result['absent_tables'])
        self.assertEqual({'legacy': ['b']}, result['absent_columns'])


if __name__ == '__main__':
    unittest.main()
