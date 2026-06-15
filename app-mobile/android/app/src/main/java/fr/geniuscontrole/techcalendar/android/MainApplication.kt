package fr.geniuscontrole.techcalendar.android

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import com.facebook.react.PackageList
import com.facebook.react.ReactApplication
import com.facebook.react.ReactHost
import com.facebook.react.ReactNativeApplicationEntryPoint.loadReactNative
import com.facebook.react.defaults.DefaultReactHost.getDefaultReactHost

class MainApplication : Application(), ReactApplication {

  override val reactHost: ReactHost by lazy {
    getDefaultReactHost(
      context = applicationContext,
      packageList =
        PackageList(this).packages.apply {
          // Packages that cannot be autolinked yet can be added manually here, for example:
          // add(MyReactNativePackage())
        },
    )
  }

  override fun onCreate() {
    super.onCreate()
    createNotificationChannels()
    loadReactNative(this)
  }

  private fun createNotificationChannels() {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
      return
    }

    val notificationManager = getSystemService(NotificationManager::class.java)
    val appointmentsChannel = NotificationChannel(
      "appointments",
      "Rendez-vous",
      NotificationManager.IMPORTANCE_HIGH,
    ).apply {
      description = "Notifications de placement, modification et annulation de rendez-vous"
    }

    notificationManager.createNotificationChannel(appointmentsChannel)
  }
}
