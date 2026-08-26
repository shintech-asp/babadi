import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pestify/main.dart';

void main() {
  testWidgets('PestifyApp smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: PestifyApp()));
  });
}
