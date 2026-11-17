import 'dart:async';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Offline-first message & diagnosis sync queue (30-day local cache + outgoing queue).
class QueuedOutgoingTurn {
  final String id;
  final String phone;
  final String language;
  final String? messageText;
  final String? photoBase64;
  final double? latitude;
  final double? longitude;
  final DateTime queuedAt;

  static const int cacheRetentionDays = 30;

  QueuedOutgoingTurn({
    required this.id,
    required this.phone,
    required this.language,
    this.messageText,
    this.photoBase64,
    this.latitude,
    this.longitude,
    required this.queuedAt,
  });
}

class OfflineSyncService {
  final Dio _dio;
  final List<QueuedOutgoingTurn> _pendingQueue = [];
  final List<Map<String, dynamic>> _cachedDiagnoses = [];

  OfflineSyncService(this._dio);

  List<QueuedOutgoingTurn> get pendingQueue => List.unmodifiable(_pendingQueue);
  List<Map<String, dynamic>> get cachedDiagnoses => List.unmodifiable(_cachedDiagnoses);

  /// Enqueue a farmer turn (works offline; flushes immediately when online).
  Future<Map<String, dynamic>> sendOrQueueTurn(QueuedOutgoingTurn turn) async {
    try {
      final response = await _dio.post(
        '/api/v1/web-chat/messages',
        data: {
          'phone': turn.phone,
          'language': turn.language,
          'message_text': turn.messageText,
          'photo_base64': turn.photoBase64,
          'latitude': turn.latitude,
          'longitude': turn.longitude,
        },
        options: Options(headers: {'X-Tenant-Slug': 'farmserve-zimbabwe'}),
      );

      final data = (response.data['data'] ?? response.data) as Map<String, dynamic>;
      _cacheTurnResult(data);
      return data;
    } on DioException {
      _pendingQueue.add(turn);
      return {
        'state': 'OFFLINE_QUEUED',
        'context_chip': 'Offline Mode (Queued for sync)',
        'reply_text':
            '📶 Saved offline. Your photo/message will be sent automatically as soon as mobile data connects.',
        'buttons': <Map<String, dynamic>>[],
        'rich_cards': <Map<String, dynamic>>[],
      };
    }
  }

  /// Flush all queued offline messages when connectivity returns.
  Future<int> flushOfflineQueue() async {
    int syncedCount = 0;
    final copy = List<QueuedOutgoingTurn>.from(_pendingQueue);

    for (final item in copy) {
      try {
        final response = await _dio.post(
          '/api/v1/web-chat/messages',
          data: {
            'phone': item.phone,
            'language': item.language,
            'message_text': item.messageText,
            'photo_base64': item.photoBase64,
            'latitude': item.latitude,
            'longitude': item.longitude,
          },
          options: Options(headers: {'X-Tenant-Slug': 'farmserve-zimbabwe'}),
        );
        final data = (response.data['data'] ?? response.data) as Map<String, dynamic>;
        _cacheTurnResult(data);
        _pendingQueue.remove(item);
        syncedCount++;
      } on DioException {
        break;
      }
    }
    return syncedCount;
  }

  void _cacheTurnResult(Map<String, dynamic> data) {
    final cutoff = DateTime.now().subtract(
      const Duration(days: QueuedOutgoingTurn.cacheRetentionDays),
    );
    _cachedDiagnoses.removeWhere((entry) {
      final ts = DateTime.tryParse((entry['cached_at'] ?? '').toString());
      return ts != null && ts.isBefore(cutoff);
    });
    _cachedDiagnoses.insert(0, {
      ...data,
      'cached_at': DateTime.now().toIso8601String(),
    });
  }
}

final dioProvider = Provider<Dio>((ref) {
  return Dio(BaseOptions(
    baseUrl: 'http://localhost:8000',
    connectTimeout: const Duration(seconds: 15),
    receiveTimeout: const Duration(seconds: 30),
  ));
});

final offlineSyncProvider = Provider<OfflineSyncService>((ref) {
  return OfflineSyncService(ref.watch(dioProvider));
});
